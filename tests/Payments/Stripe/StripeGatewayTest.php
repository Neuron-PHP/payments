<?php

namespace Tests\Payments\Stripe;

use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Frequency;
use Neuron\Payments\Dto\LineItem;
use Neuron\Payments\Dto\Money;
use Neuron\Payments\Dto\WebhookEvent;
use Neuron\Payments\Exceptions\PaymentException;
use Neuron\Payments\Stripe\StripeGateway;
use PHPUnit\Framework\TestCase;

class StripeGatewayTest extends TestCase
{
	private const SECRET = 'whsec_test_secret';

	private function gateway(): StripeGateway
	{
		return new StripeGateway( 'sk_test_x', self::SECRET );
	}

	private function request( Frequency $frequency ): CheckoutSessionRequest
	{
		return new CheckoutSessionRequest(
			amount:      new Money( 5000, 'usd' ),
			frequency:   $frequency,
			successUrl:  'https://example.org/success',
			cancelUrl:   'https://example.org/cancel',
			productName: 'Donation',
			customerEmail: 'donor@example.org',
			metadata:    [ 'donation_id' => 7 ]
		);
	}

	public function testBuildSessionParamsOneTime(): void
	{
		$params = $this->gateway()->buildSessionParams( $this->request( Frequency::OneTime ) );

		$this->assertSame( 'payment', $params['mode'] );
		$this->assertSame( 5000, $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'usd', $params['line_items'][0]['price_data']['currency'] );
		$this->assertArrayNotHasKey( 'recurring', $params['line_items'][0]['price_data'] );
		$this->assertArrayNotHasKey( 'subscription_data', $params );
		$this->assertSame( 'donor@example.org', $params['customer_email'] );
		$this->assertSame( '7', $params['metadata']['donation_id'] );
		$this->assertSame( 'one_time', $params['metadata']['frequency'] );
	}

	public function testBuildSessionParamsRecurringQuarterly(): void
	{
		$params = $this->gateway()->buildSessionParams( $this->request( Frequency::Quarterly ) );

		$this->assertSame( 'subscription', $params['mode'] );

		$recurring = $params['line_items'][0]['price_data']['recurring'];
		$this->assertSame( 'month', $recurring['interval'] );
		$this->assertSame( 3, $recurring['interval_count'] );

		$this->assertSame( '7', $params['subscription_data']['metadata']['donation_id'] );
		$this->assertSame( 'quarterly', $params['subscription_data']['metadata']['frequency'] );
	}

	public function testBuildSessionParamsCartLineItems(): void
	{
		$request = new CheckoutSessionRequest(
			amount:      new Money( 4500, 'usd' ),
			frequency:   Frequency::OneTime,
			successUrl:  'https://example.org/success',
			cancelUrl:   'https://example.org/cancel',
			productName: 'Order',
			metadata:    [ 'payment_id' => 12 ],
			lineItems:   [
				new LineItem( 'T-Shirt', new Money( 2000, 'usd' ), 2 ),
				new LineItem( 'Sticker', new Money( 500, 'usd' ), 1 )
			]
		);

		$params = $this->gateway()->buildSessionParams( $request );

		$this->assertSame( 'payment', $params['mode'] );
		$this->assertCount( 2, $params['line_items'] );
		$this->assertSame( 'T-Shirt', $params['line_items'][0]['price_data']['product_data']['name'] );
		$this->assertSame( 2000, $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 2, $params['line_items'][0]['quantity'] );
		$this->assertSame( 500, $params['line_items'][1]['price_data']['unit_amount'] );
		$this->assertSame( '12', $params['metadata']['payment_id'] );
	}

	public function testBuildSessionParamsCartIsOneTimeEvenWhenRecurringRequested(): void
	{
		$request = new CheckoutSessionRequest(
			amount:    new Money( 2000, 'usd' ),
			frequency: Frequency::Monthly,
			successUrl: 'https://example.org/success',
			cancelUrl:  'https://example.org/cancel',
			lineItems:  [ new LineItem( 'Mug', new Money( 2000, 'usd' ), 1 ) ]
		);

		$params = $this->gateway()->buildSessionParams( $request );

		$this->assertSame( 'payment', $params['mode'] );
		$this->assertArrayNotHasKey( 'subscription_data', $params );
		$this->assertArrayNotHasKey( 'recurring', $params['line_items'][0]['price_data'] );
		$this->assertSame( 'one_time', $params['metadata']['frequency'] );
	}

	public function testVerifyWebhookAcceptsValidSignature(): void
	{
		$payload = json_encode( [
			'type' => WebhookEvent::CHECKOUT_COMPLETED,
			'data' => [ 'object' => [
				'id'             => 'cs_test_123',
				'payment_intent' => 'pi_test_456',
				'amount_total'   => 5000,
				'metadata'       => [ 'donation_id' => '7' ],
				'customer_details' => [ 'email' => 'donor@example.org' ]
			] ]
		] );

		$header = $this->signature( $payload );

		$event = $this->gateway()->verifyWebhook( $payload, $header );

		$this->assertTrue( $event->isCheckoutCompleted() );
		$this->assertSame( 'cs_test_123', $event->sessionId() );
		$this->assertSame( 'pi_test_456', $event->paymentIntentId() );
		$this->assertSame( 5000, $event->amountTotal() );
		$this->assertSame( '7', $event->metadata()['donation_id'] );
		$this->assertSame( 'donor@example.org', $event->customerEmail() );
	}

	public function testVerifyWebhookRejectsBadSignature(): void
	{
		$payload = '{"type":"checkout.session.completed","data":{"object":{}}}';
		$header  = 't=' . time() . ',v1=deadbeef';

		$this->expectException( PaymentException::class );

		$this->gateway()->verifyWebhook( $payload, $header );
	}

	public function testVerifyWebhookRejectsStaleTimestamp(): void
	{
		$payload   = '{"type":"checkout.session.completed","data":{"object":{}}}';
		$timestamp = time() - 10000;
		$header    = $this->signature( $payload, $timestamp );

		$this->expectException( PaymentException::class );

		$this->gateway()->verifyWebhook( $payload, $header );
	}

	public function testVerifyWebhookRequiresSecret(): void
	{
		$gateway = new StripeGateway( 'sk_test_x', '' );

		$this->expectException( PaymentException::class );

		$gateway->verifyWebhook( '{}', 't=1,v1=abc' );
	}

	public function testVerifyWebhookDecodesInvoiceRenewal(): void
	{
		$payload = json_encode( [
			'type' => WebhookEvent::INVOICE_PAID,
			'data' => [ 'object' => [
				'id'             => 'in_123',
				'subscription'   => 'sub_456',
				'amount_paid'    => 2500,
				'billing_reason' => 'subscription_cycle'
			] ]
		] );

		$event = $this->gateway()->verifyWebhook( $payload, $this->signature( $payload ) );

		$this->assertTrue( $event->isInvoicePaid() );
		$this->assertTrue( $event->isRenewal() );
		$this->assertSame( 'sub_456', $event->subscriptionId() );
		$this->assertSame( 2500, $event->amountPaid() );
	}

	public function testSubscriptionFromStripeMapsArray(): void
	{
		$subscription = $this->gateway()->subscriptionFromStripe( [
			'id'                 => 'sub_1',
			'status'             => 'active',
			'current_period_end' => 1750000000,
			'canceled_at'        => null,
			'metadata'           => [ 'payment_id' => '7' ]
		] );

		$this->assertSame( 'sub_1', $subscription->id );
		$this->assertTrue( $subscription->isActive() );
		$this->assertFalse( $subscription->isCanceled() );
		$this->assertSame( 1750000000, $subscription->currentPeriodEnd );
		$this->assertSame( '7', $subscription->metadata['payment_id'] );
	}

	public function testSessionFromStripeMapsPaidCheckout(): void
	{
		$session = $this->gateway()->sessionFromStripe( [
			'id'              => 'cs_1',
			'url'             => 'https://checkout.stripe.com/cs_1',
			'status'          => 'complete',
			'payment_status'  => 'paid',
			'payment_intent'  => 'pi_1',
			'subscription'    => null,
			'amount_total'    => 2500,
			'metadata'        => [ 'payment_id' => '9' ]
		] );

		$this->assertSame( 'cs_1', $session->id );
		$this->assertTrue( $session->isPaid() );
		$this->assertFalse( $session->isExpired() );
		$this->assertSame( 'pi_1', $session->paymentIntentId );
		$this->assertSame( 2500, $session->amountTotal );
		$this->assertSame( '9', $session->metadata['payment_id'] );
	}

	public function testSessionFromStripeMapsExpiredCheckout(): void
	{
		$session = $this->gateway()->sessionFromStripe( [
			'id'             => 'cs_2',
			'status'         => 'expired',
			'payment_status' => 'unpaid'
		] );

		$this->assertTrue( $session->isExpired() );
		$this->assertFalse( $session->isPaid() );
	}

	public function testSubscriptionFromStripeMapsCanceled(): void
	{
		$subscription = $this->gateway()->subscriptionFromStripe( [
			'id'          => 'sub_2',
			'status'      => 'canceled',
			'canceled_at' => 1750000000
		] );

		$this->assertTrue( $subscription->isCanceled() );
		$this->assertFalse( $subscription->isActive() );
		$this->assertSame( 1750000000, $subscription->canceledAt );
	}

	private function signature( string $payload, ?int $timestamp = null ): string
	{
		$timestamp ??= time();
		$signed = hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET );

		return "t={$timestamp},v1={$signed}";
	}
}

<?php

namespace Tests\Payments\Stripe;

use Neuron\Payments\Dto\CheckoutSessionRequest;
use Neuron\Payments\Dto\Frequency;
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

	private function signature( string $payload, ?int $timestamp = null ): string
	{
		$timestamp ??= time();
		$signed = hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET );

		return "t={$timestamp},v1={$signed}";
	}
}

<?php

namespace Tests\Payments\Dto;

use Neuron\Payments\Dto\WebhookEvent;
use PHPUnit\Framework\TestCase;

class WebhookEventTest extends TestCase
{
	public function testCheckoutCompletedExposesSessionAndPaymentIntent(): void
	{
		$event = new WebhookEvent( WebhookEvent::CHECKOUT_COMPLETED, [
			'id'             => 'cs_123',
			'payment_intent' => 'pi_456',
			'subscription'   => 'sub_789',
			'amount_total'   => 5000,
			'metadata'       => [ 'payment_id' => '7' ]
		] );

		$this->assertTrue( $event->isCheckoutCompleted() );
		$this->assertFalse( $event->isInvoicePaid() );
		$this->assertSame( 'cs_123', $event->sessionId() );
		$this->assertSame( 'pi_456', $event->paymentIntentId() );
		$this->assertSame( 'sub_789', $event->subscriptionId() );
		$this->assertSame( 5000, $event->amountTotal() );
		$this->assertSame( '7', $event->metadata()['payment_id'] );
	}

	public function testInvoicePaidRenewalResolvesIds(): void
	{
		$event = new WebhookEvent( WebhookEvent::INVOICE_PAID, [
			'id'             => 'in_999',
			'subscription'   => 'sub_789',
			'payment_intent' => 'pi_renew',
			'amount_paid'    => 2500,
			'billing_reason' => 'subscription_cycle',
			'customer_email' => 'donor@example.org'
		] );

		$this->assertTrue( $event->isInvoicePaid() );
		$this->assertTrue( $event->isRenewal() );
		$this->assertNull( $event->sessionId() );
		$this->assertSame( 'in_999', $event->invoiceId() );
		$this->assertSame( 'sub_789', $event->subscriptionId() );
		$this->assertSame( 'pi_renew', $event->paymentIntentId() );
		$this->assertSame( 2500, $event->amountPaid() );
		$this->assertSame( 2500, $event->amountTotal() );
		$this->assertSame( 'donor@example.org', $event->customerEmail() );
	}

	public function testInvoicePaidInitialIsNotRenewal(): void
	{
		$event = new WebhookEvent( WebhookEvent::INVOICE_PAYMENT_SUCCESS, [
			'id'             => 'in_1',
			'subscription'   => 'sub_1',
			'billing_reason' => 'subscription_create'
		] );

		$this->assertTrue( $event->isInvoicePaid() );
		$this->assertFalse( $event->isRenewal() );
	}

	public function testInvoiceMetadataFallsBackToSubscriptionDetails(): void
	{
		$event = new WebhookEvent( WebhookEvent::INVOICE_PAID, [
			'id'                   => 'in_2',
			'subscription'         => 'sub_2',
			'subscription_details' => [ 'metadata' => [ 'payment_id' => '42', 'form_key' => 'general' ] ]
		] );

		$this->assertSame( '42', $event->metadata()['payment_id'] );
		$this->assertSame( 'general', $event->metadata()['form_key'] );
	}

	public function testSubscriptionDeletedExposesStatusAndId(): void
	{
		$event = new WebhookEvent( WebhookEvent::SUBSCRIPTION_DELETED, [
			'id'                 => 'sub_789',
			'status'             => 'canceled',
			'current_period_end' => 1750000000,
			'metadata'           => [ 'payment_id' => '7' ]
		] );

		$this->assertTrue( $event->isSubscriptionDeleted() );
		$this->assertSame( 'sub_789', $event->subscriptionId() );
		$this->assertSame( 'canceled', $event->subscriptionStatus() );
		$this->assertSame( 1750000000, $event->currentPeriodEnd() );
	}

	public function testPaymentFailedIsFlagged(): void
	{
		$event = new WebhookEvent( WebhookEvent::INVOICE_PAYMENT_FAILED, [
			'id'           => 'in_3',
			'subscription' => 'sub_3'
		] );

		$this->assertTrue( $event->isInvoicePaymentFailed() );
		$this->assertFalse( $event->isInvoicePaid() );
		$this->assertSame( 'sub_3', $event->subscriptionId() );
	}

	public function testUnknownEventHasNoFlags(): void
	{
		$event = new WebhookEvent( 'customer.created', [ 'id' => 'cus_1' ] );

		$this->assertFalse( $event->isCheckoutCompleted() );
		$this->assertFalse( $event->isInvoicePaid() );
		$this->assertFalse( $event->isSubscriptionUpdated() );
		$this->assertSame( 'cus_1', $event->objectId() );
		$this->assertNull( $event->sessionId() );
	}
}

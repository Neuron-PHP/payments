<?php

namespace Neuron\Payments\Dto;

/**
 * A verified webhook event from the payment provider.
 *
 * Wraps the event type and the underlying object ( a Checkout Session, an
 * Invoice, or a Subscription depending on the event ) and exposes the fields
 * the payment / subscription flows need without leaking provider SDK types to
 * callers.
 *
 * The accessors are intentionally shape-tolerant: the same call ( e.g.
 * {@see subscriptionId()} ) returns the right id whether the underlying object
 * is a checkout session, an invoice, or the subscription itself.
 *
 * @package Neuron\Payments\Dto
 */
final class WebhookEvent
{
	public const CHECKOUT_COMPLETED      = 'checkout.session.completed';
	public const INVOICE_PAID            = 'invoice.paid';
	public const INVOICE_PAYMENT_SUCCESS = 'invoice.payment_succeeded';
	public const INVOICE_PAYMENT_FAILED  = 'invoice.payment_failed';
	public const SUBSCRIPTION_UPDATED    = 'customer.subscription.updated';
	public const SUBSCRIPTION_DELETED    = 'customer.subscription.deleted';

	/**
	 * @param string $type Provider event type ( e.g. checkout.session.completed ).
	 * @param array<string, mixed> $object The event's primary data object as an array.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $object
	)
	{
	}

	/**
	 * Whether this event signals a completed hosted checkout.
	 *
	 * @return bool
	 */
	public function isCheckoutCompleted(): bool
	{
		return $this->type === self::CHECKOUT_COMPLETED;
	}

	/**
	 * Whether this event signals a successfully paid invoice ( the initial
	 * subscription charge or a renewal ).
	 *
	 * @return bool
	 */
	public function isInvoicePaid(): bool
	{
		return $this->type === self::INVOICE_PAID
			|| $this->type === self::INVOICE_PAYMENT_SUCCESS;
	}

	/**
	 * Whether this event signals a failed invoice payment ( dunning ).
	 *
	 * @return bool
	 */
	public function isInvoicePaymentFailed(): bool
	{
		return $this->type === self::INVOICE_PAYMENT_FAILED;
	}

	/**
	 * Whether this event reports a subscription change ( status / period ).
	 *
	 * @return bool
	 */
	public function isSubscriptionUpdated(): bool
	{
		return $this->type === self::SUBSCRIPTION_UPDATED;
	}

	/**
	 * Whether this event reports a canceled / ended subscription.
	 *
	 * @return bool
	 */
	public function isSubscriptionDeleted(): bool
	{
		return $this->type === self::SUBSCRIPTION_DELETED;
	}

	/**
	 * Whether the underlying object is a subscription ( subscription.* events ).
	 *
	 * @return bool
	 */
	private function objectIsSubscription(): bool
	{
		return $this->isSubscriptionUpdated() || $this->isSubscriptionDeleted();
	}

	/**
	 * Whether the underlying object is an invoice ( invoice.* events ).
	 *
	 * @return bool
	 */
	private function objectIsInvoice(): bool
	{
		return $this->isInvoicePaid() || $this->isInvoicePaymentFailed();
	}

	/**
	 * The raw id of the event's primary object.
	 *
	 * @return string|null
	 */
	public function objectId(): ?string
	{
		return $this->string( 'id' );
	}

	/**
	 * The checkout session id, when this event is a completed checkout.
	 *
	 * @return string|null
	 */
	public function sessionId(): ?string
	{
		return $this->isCheckoutCompleted() ? $this->string( 'id' ) : null;
	}

	/**
	 * The invoice id, when present ( the object on invoice.* events ).
	 *
	 * @return string|null
	 */
	public function invoiceId(): ?string
	{
		return $this->objectIsInvoice() ? $this->string( 'id' ) : $this->string( 'invoice' );
	}

	/**
	 * The payment intent id ( one-time payments / paid invoices ), when present.
	 *
	 * @return string|null
	 */
	public function paymentIntentId(): ?string
	{
		return $this->string( 'payment_intent' );
	}

	/**
	 * The subscription id ( recurring payments ), when present.
	 *
	 * For subscription.* events the object itself is the subscription, so its
	 * `id` is returned; otherwise the `subscription` reference is used.
	 *
	 * @return string|null
	 */
	public function subscriptionId(): ?string
	{
		return $this->objectIsSubscription() ? $this->string( 'id' ) : $this->string( 'subscription' );
	}

	/**
	 * The amount captured, in the smallest currency unit.
	 *
	 * Reads `amount_total` ( checkout ) then `amount_paid` ( invoice ).
	 *
	 * @return int|null
	 */
	public function amountTotal(): ?int
	{
		$value = $this->object['amount_total'] ?? $this->object['amount_paid'] ?? null;

		return $value === null ? null : (int) $value;
	}

	/**
	 * The amount paid on an invoice, in the smallest currency unit.
	 *
	 * @return int|null
	 */
	public function amountPaid(): ?int
	{
		$value = $this->object['amount_paid'] ?? $this->object['amount_total'] ?? null;

		return $value === null ? null : (int) $value;
	}

	/**
	 * The customer email captured by the provider, when present.
	 *
	 * @return string|null
	 */
	public function customerEmail(): ?string
	{
		$details = $this->object['customer_details'] ?? null;

		if( is_array( $details ) && !empty( $details['email'] ) )
		{
			return (string) $details['email'];
		}

		return $this->string( 'customer_email' );
	}

	/**
	 * The subscription status reported by a subscription.* event
	 * ( e.g. active, past_due, canceled, unpaid ).
	 *
	 * @return string|null
	 */
	public function subscriptionStatus(): ?string
	{
		return $this->objectIsSubscription() ? $this->string( 'status' ) : null;
	}

	/**
	 * The current period end ( unix timestamp ) for a subscription, when present.
	 *
	 * @return int|null
	 */
	public function currentPeriodEnd(): ?int
	{
		$value = $this->object['current_period_end'] ?? null;

		return $value === null ? null : (int) $value;
	}

	/**
	 * The invoice billing reason ( e.g. subscription_create, subscription_cycle )
	 * which distinguishes the first charge from a renewal.
	 *
	 * @return string|null
	 */
	public function billingReason(): ?string
	{
		return $this->string( 'billing_reason' );
	}

	/**
	 * Whether a paid invoice is a renewal ( a cycle after the first charge )
	 * rather than the subscription's initial invoice.
	 *
	 * @return bool
	 */
	public function isRenewal(): bool
	{
		return $this->billingReason() === 'subscription_cycle';
	}

	/**
	 * Metadata echoed back from the original request.
	 *
	 * Checks the object's own metadata first, then Stripe's nested
	 * `subscription_details.metadata` ( present on modern invoice events ).
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array
	{
		$metadata = $this->object['metadata'] ?? [];

		if( ( !is_array( $metadata ) || $metadata === [] ) && isset( $this->object['subscription_details']['metadata'] ) )
		{
			$metadata = $this->object['subscription_details']['metadata'];
		}

		return is_array( $metadata ) ? $metadata : [];
	}

	/**
	 * Read a string field from the event object.
	 *
	 * @param string $key
	 * @return string|null
	 */
	private function string( string $key ): ?string
	{
		$value = $this->object[ $key ] ?? null;

		return is_scalar( $value ) && $value !== '' ? (string) $value : null;
	}
}

<?php

namespace Neuron\Payments\Dto;

/**
 * A verified webhook event from the payment provider.
 *
 * Wraps the event type and the underlying object ( e.g. the Checkout Session )
 * and exposes the fields the donation flow needs without leaking provider SDK
 * types to callers.
 *
 * @package Neuron\Payments\Dto
 */
final class WebhookEvent
{
	public const CHECKOUT_COMPLETED = 'checkout.session.completed';

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
	 * The checkout session id, when present.
	 *
	 * @return string|null
	 */
	public function sessionId(): ?string
	{
		return $this->string( 'id' );
	}

	/**
	 * The payment intent id ( one-time payments ), when present.
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
	 * @return string|null
	 */
	public function subscriptionId(): ?string
	{
		return $this->string( 'subscription' );
	}

	/**
	 * The amount captured, in the smallest currency unit.
	 *
	 * @return int|null
	 */
	public function amountTotal(): ?int
	{
		$value = $this->object['amount_total'] ?? null;

		return $value === null ? null : (int) $value;
	}

	/**
	 * The donor email captured at checkout, when present.
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
	 * Metadata echoed back from the original request.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array
	{
		$metadata = $this->object['metadata'] ?? [];

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

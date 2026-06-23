<?php

namespace Neuron\Payments\Dto;

/**
 * A request to open a hosted checkout session for a single payment, a
 * recurring subscription, or a multi-item ( cart ) purchase.
 *
 * The gateway never sees raw card data; the donor enters payment details on
 * the provider's hosted page and is returned to {@see $successUrl} or
 * {@see $cancelUrl}.
 *
 * Single-amount mode: pass {@see $amount} and leave {@see $lineItems} empty.
 * Cart mode: pass one or more {@see $lineItems}; {@see $amount} is then only a
 * convenience total ( e.g. for logging ) and the gateway charges the line
 * items. Cart mode is one-time only.
 *
 * @package Neuron\Payments\Dto
 */
final class CheckoutSessionRequest
{
	/**
	 * @param Money $amount Amount to charge ( single-amount mode ) or total ( cart mode ).
	 * @param Frequency $frequency One-time or recurring cadence.
	 * @param string $successUrl URL Stripe returns to after payment.
	 * @param string $cancelUrl URL Stripe returns to if the donor cancels.
	 * @param string $productName Line-item label shown on the hosted page ( single-amount mode ).
	 * @param string|null $customerEmail Pre-fills the donor email when known.
	 * @param array<string, scalar> $metadata Key/value pairs echoed back on the webhook.
	 * @param array<int, LineItem> $lineItems Optional cart line items; when non-empty the
	 *                                        gateway charges these instead of {@see $amount}.
	 */
	public function __construct(
		public readonly Money $amount,
		public readonly Frequency $frequency,
		public readonly string $successUrl,
		public readonly string $cancelUrl,
		public readonly string $productName = 'Donation',
		public readonly ?string $customerEmail = null,
		public readonly array $metadata = [],
		public readonly array $lineItems = []
	)
	{
	}

	/**
	 * Whether this request carries explicit cart line items.
	 *
	 * @return bool
	 */
	public function hasLineItems(): bool
	{
		return $this->lineItems !== [];
	}
}

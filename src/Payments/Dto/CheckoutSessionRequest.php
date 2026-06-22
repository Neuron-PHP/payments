<?php

namespace Neuron\Payments\Dto;

/**
 * A request to open a hosted checkout session for a single payment or a
 * recurring subscription.
 *
 * The gateway never sees raw card data; the donor enters payment details on
 * the provider's hosted page and is returned to {@see $successUrl} or
 * {@see $cancelUrl}.
 *
 * @package Neuron\Payments\Dto
 */
final class CheckoutSessionRequest
{
	/**
	 * @param Money $amount Amount to charge.
	 * @param Frequency $frequency One-time or recurring cadence.
	 * @param string $successUrl URL Stripe returns to after payment.
	 * @param string $cancelUrl URL Stripe returns to if the donor cancels.
	 * @param string $productName Line-item label shown on the hosted page.
	 * @param string|null $customerEmail Pre-fills the donor email when known.
	 * @param array<string, scalar> $metadata Key/value pairs echoed back on the webhook.
	 */
	public function __construct(
		public readonly Money $amount,
		public readonly Frequency $frequency,
		public readonly string $successUrl,
		public readonly string $cancelUrl,
		public readonly string $productName = 'Donation',
		public readonly ?string $customerEmail = null,
		public readonly array $metadata = []
	)
	{
	}
}

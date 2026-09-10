<?php

namespace Neuron\Payments\Dto;

/**
 * A hosted checkout session: an identifier to reconcile against, a URL to
 * redirect the payer to, and ( after retrieval ) the session's current status.
 *
 * @package Neuron\Payments\Dto
 */
final class CheckoutSession
{
	/**
	 * @param string $id Provider session identifier ( e.g. cs_test_... ).
	 * @param string $url Hosted checkout URL to redirect the donor to.
	 * @param string $status Provider session status ( open, complete, expired ).
	 * @param string $paymentStatus Provider payment status ( paid, unpaid, no_payment_required ).
	 * @param string|null $paymentIntentId Captured payment intent id, when present.
	 * @param string|null $subscriptionId Created subscription id, when present.
	 * @param int|null $amountTotal Amount in the smallest currency unit, when known.
	 * @param array<string, mixed> $metadata Metadata echoed from the original request.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $url = '',
		public readonly string $status = '',
		public readonly string $paymentStatus = '',
		public readonly ?string $paymentIntentId = null,
		public readonly ?string $subscriptionId = null,
		public readonly ?int $amountTotal = null,
		public readonly array $metadata = []
	)
	{
	}

	/**
	 * Whether the gateway reports this session as paid.
	 *
	 * @return bool
	 */
	public function isPaid(): bool
	{
		return $this->paymentStatus === 'paid'
			|| $this->paymentStatus === 'no_payment_required';
	}

	/**
	 * Whether the hosted session expired before payment.
	 *
	 * @return bool
	 */
	public function isExpired(): bool
	{
		return $this->status === 'expired';
	}
}

<?php

namespace Neuron\Payments\Dto;

/**
 * A single line on a hosted checkout: a product/charge with a unit price and a
 * quantity. Used to build multi-item ( cart ) checkout sessions.
 *
 * Line items describe one-time charges only; recurring cadence is expressed at
 * the {@see CheckoutSessionRequest} level via its {@see Frequency}.
 *
 * @package Neuron\Payments\Dto
 */
final class LineItem
{
	/**
	 * @param string $name Line-item label shown on the hosted page.
	 * @param Money $unitAmount Price for a single unit.
	 * @param int $quantity Number of units ( clamped to at least 1 ).
	 */
	public function __construct(
		public readonly string $name,
		public readonly Money $unitAmount,
		public readonly int $quantity = 1
	)
	{
	}

	/**
	 * The line total in the smallest currency unit ( unit price x quantity ).
	 *
	 * @return int
	 */
	public function subtotal(): int
	{
		return $this->unitAmount->amount * max( 1, $this->quantity );
	}
}

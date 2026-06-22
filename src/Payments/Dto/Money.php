<?php

namespace Neuron\Payments\Dto;

use Neuron\Payments\Exceptions\PaymentException;

/**
 * An immutable monetary amount stored in the smallest currency unit
 * ( e.g. cents for USD ), which is the unit Stripe expects.
 *
 * @package Neuron\Payments\Dto
 */
final class Money
{
	/**
	 * @param int $amount Amount in the smallest currency unit ( cents ).
	 * @param string $currency ISO 4217 currency code ( lowercase ).
	 */
	public function __construct(
		public readonly int $amount,
		public readonly string $currency = 'usd'
	)
	{
		if( $amount < 0 )
		{
			throw new PaymentException( 'Money amount cannot be negative.' );
		}
	}

	/**
	 * Build from a major-unit amount ( e.g. dollars ).
	 *
	 * @param float $major
	 * @param string $currency
	 * @return self
	 */
	public static function fromMajorUnits( float $major, string $currency = 'usd' ): self
	{
		return new self( (int) round( $major * 100 ), strtolower( $currency ) );
	}

	/**
	 * The amount expressed in major units ( e.g. dollars ).
	 *
	 * @return float
	 */
	public function toMajorUnits(): float
	{
		return $this->amount / 100;
	}
}

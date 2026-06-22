<?php

namespace Neuron\Payments\Dto;

/**
 * Donation / payment recurrence.
 *
 * Maps the human-facing cadence to the Stripe subscription interval pair
 * ( interval + interval_count ). One-time payments are not recurring and have
 * no interval.
 *
 * @package Neuron\Payments\Dto
 */
enum Frequency: string
{
	case OneTime    = 'one_time';
	case Monthly    = 'monthly';
	case Quarterly  = 'quarterly';
	case SemiAnnual = 'semiannual';
	case Annual     = 'annual';

	/**
	 * Resolve a value to a Frequency, defaulting to one-time when unknown.
	 *
	 * @param mixed $value
	 * @return self
	 */
	public static function fromValue( mixed $value ): self
	{
		if( $value instanceof self )
		{
			return $value;
		}

		return self::tryFrom( is_scalar( $value ) ? (string) $value : '' ) ?? self::OneTime;
	}

	/**
	 * Whether this frequency represents a recurring (subscription) payment.
	 *
	 * @return bool
	 */
	public function isRecurring(): bool
	{
		return $this !== self::OneTime;
	}

	/**
	 * The Stripe interval unit ( "month" or "year" ) for recurring payments.
	 *
	 * @return string|null Null for one-time payments.
	 */
	public function stripeInterval(): ?string
	{
		return match( $this )
		{
			self::OneTime               => null,
			self::Monthly, self::Quarterly, self::SemiAnnual => 'month',
			self::Annual                => 'year'
		};
	}

	/**
	 * The Stripe interval_count for recurring payments.
	 *
	 * @return int
	 */
	public function stripeIntervalCount(): int
	{
		return match( $this )
		{
			self::OneTime, self::Monthly, self::Annual => 1,
			self::Quarterly                            => 3,
			self::SemiAnnual                           => 6
		};
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label(): string
	{
		return match( $this )
		{
			self::OneTime    => 'One-time',
			self::Monthly    => 'Monthly',
			self::Quarterly  => 'Quarterly',
			self::SemiAnnual => 'Semi-annually',
			self::Annual     => 'Annually'
		};
	}
}

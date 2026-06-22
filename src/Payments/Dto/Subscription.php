<?php

namespace Neuron\Payments\Dto;

/**
 * A provider subscription's lifecycle snapshot.
 *
 * Returned by the gateway's subscription operations so callers can react to a
 * recurring agreement without depending on provider SDK types.
 *
 * @package Neuron\Payments\Dto
 */
final class Subscription
{
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PAST_DUE = 'past_due';
	public const STATUS_CANCELED = 'canceled';
	public const STATUS_UNPAID   = 'unpaid';

	/**
	 * @param string $id Provider subscription id.
	 * @param string $status Provider status ( active, past_due, canceled, ... ).
	 * @param int|null $currentPeriodEnd Unix timestamp the current period ends.
	 * @param int|null $canceledAt Unix timestamp the subscription was canceled.
	 * @param array<string, mixed> $metadata Metadata echoed back from creation.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly ?int $currentPeriodEnd = null,
		public readonly ?int $canceledAt = null,
		public readonly array $metadata = []
	)
	{
	}

	/**
	 * Whether the subscription is currently active.
	 *
	 * @return bool
	 */
	public function isActive(): bool
	{
		return $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * Whether the subscription has been canceled / ended.
	 *
	 * @return bool
	 */
	public function isCanceled(): bool
	{
		return $this->status === self::STATUS_CANCELED;
	}
}

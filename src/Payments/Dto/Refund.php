<?php

namespace Neuron\Payments\Dto;

/**
 * The result of issuing a refund.
 *
 * @package Neuron\Payments\Dto
 */
final class Refund
{
	/**
	 * @param string $id Provider refund identifier.
	 * @param string $status Provider refund status ( e.g. "succeeded" ).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status
	)
	{
	}
}

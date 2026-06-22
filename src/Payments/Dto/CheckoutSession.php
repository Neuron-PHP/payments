<?php

namespace Neuron\Payments\Dto;

/**
 * The result of opening a checkout session: an identifier to reconcile
 * against later and a hosted URL to redirect the donor to.
 *
 * @package Neuron\Payments\Dto
 */
final class CheckoutSession
{
	/**
	 * @param string $id Provider session identifier ( e.g. cs_test_... ).
	 * @param string $url Hosted checkout URL to redirect the donor to.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $url
	)
	{
	}
}

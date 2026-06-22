<?php

namespace Neuron\Payments;

use Neuron\Payments\Exceptions\PaymentException;
use Neuron\Payments\Stripe\StripeGateway;

/**
 * Builds a payment gateway from a plain configuration array.
 *
 * Kept free of any settings/framework dependency so the component stays
 * self-contained; the host application passes in the relevant config section.
 *
 * @package Neuron\Payments
 */
class GatewayFactory
{
	/**
	 * @param array<string, mixed> $config
	 *   Expected keys:
	 *     provider:       "stripe" ( default )
	 *     secret_key:     Stripe secret API key
	 *     webhook_secret: Stripe webhook signing secret
	 * @return IPaymentGateway
	 * @throws PaymentException When the provider is unknown.
	 */
	public static function create( array $config ): IPaymentGateway
	{
		$provider = strtolower( (string) ( $config['provider'] ?? 'stripe' ) );

		return match( $provider )
		{
			'stripe' => new StripeGateway(
				(string) ( $config['secret_key'] ?? '' ),
				(string) ( $config['webhook_secret'] ?? '' )
			),
			default => throw new PaymentException( "Unknown payment provider: {$provider}" )
		};
	}
}

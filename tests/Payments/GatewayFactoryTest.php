<?php

namespace Tests\Payments;

use Neuron\Payments\GatewayFactory;
use Neuron\Payments\Exceptions\PaymentException;
use Neuron\Payments\Stripe\StripeGateway;
use PHPUnit\Framework\TestCase;

class GatewayFactoryTest extends TestCase
{
	public function testCreatesStripeGatewayByDefault(): void
	{
		$gateway = GatewayFactory::create( [
			'secret_key'     => 'sk_test_x',
			'webhook_secret' => 'whsec_x'
		] );

		$this->assertInstanceOf( StripeGateway::class, $gateway );
	}

	public function testUnknownProviderThrows(): void
	{
		$this->expectException( PaymentException::class );

		GatewayFactory::create( [ 'provider' => 'paypal' ] );
	}
}

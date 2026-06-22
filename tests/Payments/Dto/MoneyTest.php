<?php

namespace Tests\Payments\Dto;

use Neuron\Payments\Dto\Money;
use Neuron\Payments\Exceptions\PaymentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
	public function testFromMajorUnitsConvertsToCents(): void
	{
		$money = Money::fromMajorUnits( 50.00 );

		$this->assertSame( 5000, $money->amount );
		$this->assertSame( 'usd', $money->currency );
		$this->assertSame( 50.0, $money->toMajorUnits() );
	}

	public function testRoundsFractionalCents(): void
	{
		$this->assertSame( 1099, Money::fromMajorUnits( 10.99 )->amount );
		$this->assertSame( 2500, Money::fromMajorUnits( 25.001 )->amount );
	}

	public function testLowercasesCurrency(): void
	{
		$this->assertSame( 'eur', Money::fromMajorUnits( 5, 'EUR' )->currency );
	}

	public function testNegativeAmountThrows(): void
	{
		$this->expectException( PaymentException::class );

		new Money( -1 );
	}
}

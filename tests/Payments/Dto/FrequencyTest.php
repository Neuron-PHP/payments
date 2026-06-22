<?php

namespace Tests\Payments\Dto;

use Neuron\Payments\Dto\Frequency;
use PHPUnit\Framework\TestCase;

class FrequencyTest extends TestCase
{
	public function testOneTimeIsNotRecurring(): void
	{
		$this->assertFalse( Frequency::OneTime->isRecurring() );
		$this->assertNull( Frequency::OneTime->stripeInterval() );
	}

	public function testRecurringFlags(): void
	{
		$this->assertTrue( Frequency::Monthly->isRecurring() );
		$this->assertTrue( Frequency::Annual->isRecurring() );
	}

	/**
	 * @dataProvider intervalProvider
	 */
	public function testStripeIntervalMapping( Frequency $frequency, ?string $interval, int $count ): void
	{
		$this->assertSame( $interval, $frequency->stripeInterval() );
		$this->assertSame( $count, $frequency->stripeIntervalCount() );
	}

	public static function intervalProvider(): array
	{
		return [
			'monthly'    => [ Frequency::Monthly, 'month', 1 ],
			'quarterly'  => [ Frequency::Quarterly, 'month', 3 ],
			'semiannual' => [ Frequency::SemiAnnual, 'month', 6 ],
			'annual'     => [ Frequency::Annual, 'year', 1 ]
		];
	}

	public function testFromValueDefaultsToOneTime(): void
	{
		$this->assertSame( Frequency::OneTime, Frequency::fromValue( 'nonsense' ) );
		$this->assertSame( Frequency::OneTime, Frequency::fromValue( null ) );
		$this->assertSame( Frequency::Monthly, Frequency::fromValue( 'monthly' ) );
		$this->assertSame( Frequency::Annual, Frequency::fromValue( Frequency::Annual ) );
	}
}

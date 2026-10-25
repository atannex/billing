<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Unit;

use Atannex\Billing\Money\Currency;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_major_units_exactly(): void
    {
        $this->assertSame(5000, Money::of(5000, 'XAF')->amount);
        $this->assertSame(1999, Money::of('19.99', 'USD')->amount);
        $this->assertSame(1250, Money::of(12.5, 'usd')->amount);
        $this->assertSame(30, Money::of(0.1 + 0.2, 'EUR')->amount);
        $this->assertSame(1500, Money::of('1.5', 'KWD')->amount);
        $this->assertSame(-250, Money::of('-2.50', 'USD')->amount);
        $this->assertSame('USD', Money::of(1, 'usd')->currency);
    }

    #[Test]
    public function it_refuses_precision_the_currency_does_not_have(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('5000.5', 'XAF');
    }

    #[Test]
    public function it_refuses_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('12,50', 'USD');
    }

    #[Test]
    public function it_renders_decimals(): void
    {
        $this->assertSame('5000', Money::minor(5000, 'XAF')->decimal());
        $this->assertSame('12.05', Money::minor(1205, 'USD')->decimal());
        $this->assertSame('0.05', Money::minor(5, 'USD')->decimal());
        $this->assertSame('-0.05', Money::minor(-5, 'USD')->decimal());
        $this->assertSame('1.500', Money::minor(1500, 'BHD')->decimal());
        $this->assertSame('12.05 USD', (string) Money::minor(1205, 'USD'));
    }

    #[Test]
    public function it_does_arithmetic_within_one_currency(): void
    {
        $a = Money::of(3000, 'XAF');
        $b = Money::of(2000, 'XAF');

        $this->assertTrue($a->plus($b)->equals(Money::of(5000, 'XAF')));
        $this->assertTrue($a->minus($b)->equals(Money::of(1000, 'XAF')));
        $this->assertTrue($a->greaterThan($b));
        $this->assertFalse($a->equals(Money::of(3000, 'XOF')));

        $this->expectException(InvalidArgumentException::class);
        $a->plus(Money::of(1, 'USD'));
    }

    #[Test]
    public function decimals_can_be_configured(): void
    {
        $this->assertSame(0, Currency::decimals('OUV'));
        $this->assertSame(0, Currency::decimals('XAF'));
        $this->assertSame(2, Currency::decimals('EUR'));
        $this->assertSame(3, Currency::decimals('TND'));
    }

    #[Test]
    public function it_serializes(): void
    {
        $this->assertSame(['amount' => 1250, 'currency' => 'USD', 'decimal' => '12.50'], Money::minor(1250, 'USD')->jsonSerialize());
        $this->assertNotSame('', Money::of(5000, 'XAF')->format('fr'));
    }
}

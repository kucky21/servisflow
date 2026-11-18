<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Model\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testParsesCzechDecimalComma(): void
    {
        self::assertSame('1234.50', Money::of('1 234,5')->toDecimalString());
    }

    public function testRoundsHalfUpToCents(): void
    {
        self::assertSame('10.01', Money::of('10.005')->toDecimalString());
        self::assertSame('10.00', Money::of('10.004')->toDecimalString());
    }

    public function testArithmetic(): void
    {
        $price = Money::of('199.90');

        self::assertSame('599.70', $price->multipliedBy(3)->toDecimalString());
        self::assertSame('41.98', $price->percentage('21')->toDecimalString());
        self::assertSame('99.90', $price->minus(Money::of('100'))->toDecimalString());
        self::assertSame('300.00', Money::sum([Money::of('100'), Money::of('200')])->toDecimalString());
    }

    public function testPriceForMinutes(): void
    {
        self::assertSame('445.00', Money::of('890')->forMinutes(30)->toDecimalString());
        self::assertSame('192.83', Money::of('890')->forMinutes(13)->toDecimalString());
    }

    public function testFormatsForCzechLocale(): void
    {
        self::assertSame("1\u{00A0}234\u{00A0}567,89\u{00A0}Kč", Money::of('1234567.89')->format());
        self::assertSame('12,00', Money::of('12')->format(false));
        self::assertSame("−1\u{00A0}000,00\u{00A0}Kč", Money::of('-1000')->format());
    }

    public function testNullableFactory(): void
    {
        self::assertNull(Money::ofNullable(null));
        self::assertNull(Money::ofNullable(''));
        self::assertSame('5.00', Money::ofNullable('5')?->toDecimalString());
    }
}

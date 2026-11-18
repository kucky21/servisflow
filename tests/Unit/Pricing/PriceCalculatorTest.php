<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\DTO\Pricing\LaborLine;
use App\DTO\Pricing\MaterialLine;
use App\Model\Money\Money;
use App\Services\Pricing\PriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PriceCalculator();
    }

    public function testCalculatesFullBreakdown(): void
    {
        $result = $this->calculator->calculate(
            [new LaborLine('Reflash BIOSu', 90, Money::of('890.00'))],
            [new MaterialLine('SSD 500 GB', 1, Money::of('1690.00'))],
            '10',
            '21',
        );

        self::assertSame('1335.00', $result->laborTotal->toDecimalString());
        self::assertSame('1690.00', $result->materialTotal->toDecimalString());
        self::assertSame('3025.00', $result->subtotal->toDecimalString());
        self::assertSame('302.50', $result->discountAmount->toDecimalString());
        self::assertSame('2722.50', $result->taxBase->toDecimalString());
        self::assertSame('571.73', $result->vatAmount->toDecimalString());
        self::assertSame('3294.23', $result->total->toDecimalString());
        self::assertTrue($result->hasDiscount());
        self::assertSame(90, $result->getTotalMinutes());
    }

    public function testSumsMultipleLinesAndQuantities(): void
    {
        $result = $this->calculator->calculate(
            [
                new LaborLine('Diagnostika', 30, Money::of('890')),
                new LaborLine('Výměna displeje', 45, Money::of('890')),
            ],
            [
                new MaterialLine('Displej', 1, Money::of('4990')),
                new MaterialLine('Lepicí páska', 3, Money::of('150')),
            ],
            '0',
            '21',
        );

        // 445 + 667.50 = 1112.50; 4990 + 450 = 5440
        self::assertSame('1112.50', $result->laborTotal->toDecimalString());
        self::assertSame('5440.00', $result->materialTotal->toDecimalString());
        self::assertSame('6552.50', $result->taxBase->toDecimalString());
        self::assertSame('1376.03', $result->vatAmount->toDecimalString()); // 1376.025 → HALF_UP
        self::assertSame('7928.53', $result->total->toDecimalString());
        self::assertFalse($result->hasDiscount());
    }

    public function testAvoidsFloatingPointErrors(): void
    {
        // 0.1 + 0.2 by ve float aritmetice nebylo 0.3
        $result = $this->calculator->calculate(
            [],
            [new MaterialLine('A', 1, Money::of('0.10')), new MaterialLine('B', 1, Money::of('0.20'))],
            '0',
            '0',
        );

        self::assertSame('0.30', $result->total->toDecimalString());
    }

    public function testEmptyOrderIsZero(): void
    {
        $result = $this->calculator->calculate([], [], '0', '21');

        self::assertTrue($result->total->isZero());
        self::assertTrue($result->isEmpty());
    }

    public function testFullDiscountResultsInZero(): void
    {
        $result = $this->calculator->calculate([new LaborLine('Práce', 60, Money::of('500'))], [], '100', '21');

        self::assertSame('0.00', $result->total->toDecimalString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRates(): iterable
    {
        yield 'záporná sleva' => ['-1', '21'];
        yield 'sleva nad 100 %' => ['100.01', '21'];
        yield 'záporné DPH' => ['0', '-5'];
    }

    #[DataProvider('invalidRates')]
    public function testRejectsInvalidRates(string $discount, string $vat): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate([], [], $discount, $vat);
    }

    public function testLaborLineRejectsNonPositiveTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LaborLine('Nic', 0, Money::of('890'));
    }

    public function testMaterialLineRejectsNonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MaterialLine('Nic', 0, Money::of('100'));
    }
}

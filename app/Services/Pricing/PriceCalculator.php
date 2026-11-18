<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTO\Pricing\LaborLine;
use App\DTO\Pricing\MaterialLine;
use App\DTO\Pricing\PriceBreakdown;
use App\Model\Money\Money;
use Brick\Math\BigDecimal;

/**
 * Čistý (bezstavový) výpočet ceny zakázky. Nezná databázi – vstupem jsou řádky práce a materiálu.
 *
 * Pravidla zaokrouhlení:
 *  - každý řádek se zaokrouhluje na haléře (HALF_UP),
 *  - sleva se počítá z mezisoučtu a zaokrouhluje na haléře,
 *  - DPH se počítá ze základu daně (po slevě) a zaokrouhluje na haléře.
 */
final class PriceCalculator
{
    /**
     * @param list<LaborLine> $laborLines
     * @param list<MaterialLine> $materialLines
     */
    public function calculate(
        array $laborLines,
        array $materialLines,
        BigDecimal|string $discountPercent,
        BigDecimal|string $vatRate,
    ): PriceBreakdown {
        $discountPercent = BigDecimal::of($discountPercent);
        $vatRate = BigDecimal::of($vatRate);

        if ($discountPercent->isNegative() || $discountPercent->isGreaterThan(100)) {
            throw new \InvalidArgumentException('Sleva musí být v rozsahu 0–100 %.');
        }
        if ($vatRate->isNegative() || $vatRate->isGreaterThan(100)) {
            throw new \InvalidArgumentException('Sazba DPH musí být v rozsahu 0–100 %.');
        }

        $laborTotal = Money::sum(array_map(static fn (LaborLine $l): Money => $l->getTotal(), $laborLines));
        $materialTotal = Money::sum(array_map(static fn (MaterialLine $m): Money => $m->getTotal(), $materialLines));

        $subtotal = $laborTotal->plus($materialTotal);
        $discountAmount = $subtotal->percentage($discountPercent);
        $taxBase = $subtotal->minus($discountAmount);
        $vatAmount = $taxBase->percentage($vatRate);
        $total = $taxBase->plus($vatAmount);

        return new PriceBreakdown(
            laborLines: $laborLines,
            materialLines: $materialLines,
            laborTotal: $laborTotal,
            materialTotal: $materialTotal,
            subtotal: $subtotal,
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            taxBase: $taxBase,
            vatRate: $vatRate,
            vatAmount: $vatAmount,
            total: $total,
        );
    }
}

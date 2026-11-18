<?php

declare(strict_types=1);

namespace App\DTO\Pricing;

use App\Model\Money\Money;
use Brick\Math\BigDecimal;

/**
 * Výsledek výpočtu ceny zakázky.
 *
 *   cena práce + cena materiálu = mezisoučet
 *   mezisoučet − sleva          = základ daně
 *   základ daně + DPH           = celkem
 */
final readonly class PriceBreakdown
{
    /**
     * @param list<LaborLine> $laborLines
     * @param list<MaterialLine> $materialLines
     */
    public function __construct(
        public array $laborLines,
        public array $materialLines,
        public Money $laborTotal,
        public Money $materialTotal,
        public Money $subtotal,
        public BigDecimal $discountPercent,
        public Money $discountAmount,
        public Money $taxBase,
        public BigDecimal $vatRate,
        public Money $vatAmount,
        public Money $total,
    ) {
    }

    public function getTotalMinutes(): int
    {
        return array_sum(array_map(static fn (LaborLine $l): int => $l->minutes, $this->laborLines));
    }

    public function hasDiscount(): bool
    {
        return !$this->discountAmount->isZero();
    }

    public function isEmpty(): bool
    {
        return $this->laborLines === [] && $this->materialLines === [];
    }
}

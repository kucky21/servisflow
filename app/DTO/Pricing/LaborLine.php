<?php

declare(strict_types=1);

namespace App\DTO\Pricing;

use App\Model\Money\Money;

/**
 * Pracovní úkon pro výpočet ceny: čas v minutách × hodinová sazba platná v okamžiku výkonu.
 */
final readonly class LaborLine
{
    public function __construct(
        public string $description,
        public int $minutes,
        public Money $hourlyRate,
    ) {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Doba práce musí být kladná.');
        }
        if ($hourlyRate->isNegative()) {
            throw new \InvalidArgumentException('Hodinová sazba nesmí být záporná.');
        }
    }

    public function getTotal(): Money
    {
        return $this->hourlyRate->forMinutes($this->minutes);
    }
}

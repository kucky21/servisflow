<?php

declare(strict_types=1);

namespace App\DTO\Pricing;

use App\Model\Money\Money;

/**
 * Spotřebovaný materiál pro výpočet ceny – s historickou jednotkovou cenou.
 */
final readonly class MaterialLine
{
    public function __construct(
        public string $name,
        public int $quantity,
        public Money $unitPrice,
        public string $sku = '',
        public string $unit = 'ks',
    ) {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Množství materiálu musí být kladné.');
        }
        if ($unitPrice->isNegative()) {
            throw new \InvalidArgumentException('Cena materiálu nesmí být záporná.');
        }
    }

    public function getTotal(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity);
    }
}

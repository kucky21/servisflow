<?php

declare(strict_types=1);

namespace App\DTO\Filter;

final readonly class InventoryFilter
{
    public function __construct(
        public ?string $query = null,
        public bool $lowStockOnly = false,
        public bool $includeInactive = false,
    ) {
    }
}

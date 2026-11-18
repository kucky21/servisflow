<?php

declare(strict_types=1);

namespace App\DTO\Input;

final readonly class InventoryItemData
{
    public function __construct(
        public string $sku,
        public string $name,
        public ?string $description,
        public string $unit,
        public string $purchasePrice,
        public string $salePrice,
        public int $minQuantity,
        public bool $isActive = true,
        public int $initialQuantity = 0,
    ) {
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public static function fromArray(iterable $values): self
    {
        $v = Normalizer::toArray($values);

        return new self(
            sku: mb_strtoupper(Normalizer::string($v['sku'] ?? '')),
            name: Normalizer::string($v['name'] ?? ''),
            description: Normalizer::nullableString($v['description'] ?? null),
            unit: Normalizer::nullableString($v['unit'] ?? null) ?? 'ks',
            purchasePrice: Normalizer::nullableDecimal($v['purchase_price'] ?? null) ?? '0',
            salePrice: Normalizer::nullableDecimal($v['sale_price'] ?? null) ?? '0',
            minQuantity: max(0, (int) ($v['min_quantity'] ?? 0)),
            isActive: (bool) ($v['is_active'] ?? true),
            initialQuantity: max(0, (int) ($v['initial_quantity'] ?? 0)),
        );
    }
}

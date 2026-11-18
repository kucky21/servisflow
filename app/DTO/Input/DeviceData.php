<?php

declare(strict_types=1);

namespace App\DTO\Input;

use App\Model\Enum\DeviceType;

final readonly class DeviceData
{
    public function __construct(
        public int $customerId,
        public DeviceType $type,
        public string $manufacturer,
        public string $model,
        public ?string $serialNumber,
        public ?string $description,
    ) {
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public static function fromArray(iterable $values): self
    {
        $v = Normalizer::toArray($values);

        return new self(
            customerId: (int) ($v['customer_id'] ?? 0),
            type: DeviceType::tryFrom((string) ($v['type'] ?? '')) ?? DeviceType::Other,
            manufacturer: Normalizer::string($v['manufacturer'] ?? ''),
            model: Normalizer::string($v['model'] ?? ''),
            serialNumber: ($sn = Normalizer::nullableString($v['serial_number'] ?? null)) === null ? null : strtoupper($sn),
            description: Normalizer::nullableString($v['description'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'customer_id' => $this->customerId,
            'type' => $this->type->value,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serialNumber,
            'description' => $this->description,
        ];
    }
}

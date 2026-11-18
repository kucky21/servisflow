<?php

declare(strict_types=1);

namespace App\DTO\Input;

use App\Model\Enum\OrderPriority;

/**
 * Údaje servisní zakázky z formuláře (vytvoření i úprava).
 * Při úpravě se customerId/deviceId ignorují – zakázku nelze převést na jiné zařízení.
 */
final readonly class OrderData
{
    public function __construct(
        public int $customerId,
        public int $deviceId,
        public ?int $technicianId,
        public OrderPriority $priority,
        public string $problemDescription,
        public ?string $accessories,
        public ?string $diagnosis,
        public ?string $estimatedPrice,
        public ?\DateTimeImmutable $dueDate,
        public ?string $internalNote,
        public ?string $hourlyRate = null,
        public ?string $discountPercent = null,
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
            deviceId: (int) ($v['device_id'] ?? 0),
            technicianId: Normalizer::nullableInt($v['assigned_technician_id'] ?? null),
            priority: OrderPriority::tryFrom((string) ($v['priority'] ?? '')) ?? OrderPriority::Normal,
            problemDescription: Normalizer::string($v['problem_description'] ?? ''),
            accessories: Normalizer::nullableString($v['accessories'] ?? null),
            diagnosis: Normalizer::nullableString($v['diagnosis'] ?? null),
            estimatedPrice: Normalizer::nullableDecimal($v['estimated_price'] ?? null),
            dueDate: Normalizer::nullableDate($v['due_date'] ?? null),
            internalNote: Normalizer::nullableString($v['internal_note'] ?? null),
            hourlyRate: Normalizer::nullableDecimal($v['hourly_rate'] ?? null),
            discountPercent: Normalizer::nullableDecimal($v['discount_percent'] ?? null),
        );
    }
}

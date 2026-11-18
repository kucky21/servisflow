<?php

declare(strict_types=1);

namespace App\DTO\Filter;

final readonly class AuditFilter
{
    public function __construct(
        public ?string $entityType = null,
        public ?int $userId = null,
        public ?string $action = null,
    ) {
    }
}

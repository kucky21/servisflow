<?php

declare(strict_types=1);

namespace App\DTO\Filter;

use App\Model\Enum\OrderPriority;
use App\Model\Enum\OrderStatus;

final readonly class OrderFilter
{
    public const string SCOPE_ACTIVE = 'active';
    public const string SCOPE_DONE = 'done';
    public const string SCOPE_ALL = 'all';

    public function __construct(
        public ?string $query = null,
        public ?OrderStatus $status = null,
        public string $scope = self::SCOPE_ACTIVE,
        public ?int $technicianId = null,
        public ?OrderPriority $priority = null,
        public bool $overdueOnly = false,
        public bool $unassignedOnly = false,
    ) {
    }
}

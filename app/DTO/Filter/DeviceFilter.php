<?php

declare(strict_types=1);

namespace App\DTO\Filter;

use App\Model\Enum\DeviceType;

final readonly class DeviceFilter
{
    public function __construct(
        public ?string $query = null,
        public ?DeviceType $type = null,
    ) {
    }
}

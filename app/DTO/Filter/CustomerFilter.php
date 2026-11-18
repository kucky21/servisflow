<?php

declare(strict_types=1);

namespace App\DTO\Filter;

use App\Model\Enum\CustomerType;

final readonly class CustomerFilter
{
    public function __construct(
        public ?string $query = null,
        public ?CustomerType $type = null,
        public string $sort = 'name',
    ) {
    }
}

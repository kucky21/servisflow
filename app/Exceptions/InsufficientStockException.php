<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InsufficientStockException extends DomainException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Nedostatek položky %s na skladě: požadováno %d, k dispozici %d.',
            $sku,
            $requested,
            $available,
        ));
    }
}

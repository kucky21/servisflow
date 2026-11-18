<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Model\Enum\OrderStatus;

/**
 * Operaci nelze provést, protože zakázka je ve stavu, který ji neumožňuje.
 */
final class OrderLockedException extends DomainException
{
    public static function forStatus(OrderStatus $status, string $operation): self
    {
        return new self(sprintf('%s není ve stavu „%s“ možné.', $operation, $status->label()));
    }
}

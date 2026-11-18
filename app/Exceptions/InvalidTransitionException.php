<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Model\Enum\OrderStatus;

/**
 * Nepovolený přechod mezi stavy zakázky nebo nesplněná podmínka přechodu.
 */
final class InvalidTransitionException extends DomainException
{
    public static function notAllowed(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf(
            'Přechod ze stavu „%s“ do stavu „%s“ není povolen.',
            $from->label(),
            $to->label(),
        ));
    }

    public static function roleNotAllowed(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf(
            'Vaše role nemůže změnit stav „%s“ na „%s“.',
            $from->label(),
            $to->label(),
        ));
    }

    public static function guardFailed(string $reason): self
    {
        return new self($reason);
    }
}

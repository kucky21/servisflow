<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AccessDeniedException extends DomainException
{
    public function __construct(string $message = 'K této operaci nemáte oprávnění.')
    {
        parent::__construct($message);
    }
}

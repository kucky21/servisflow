<?php

declare(strict_types=1);

namespace App\Exceptions;

final class EntityNotFoundException extends DomainException
{
    public static function create(string $entity, int|string $id): self
    {
        return new self(sprintf('Záznam „%s“ s ID %s nebyl nalezen.', $entity, (string) $id));
    }
}

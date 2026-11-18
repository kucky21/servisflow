<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Chyba validace vstupu na úrovni služby. Může nést chyby navázané na konkrétní pole.
 */
final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(string $message, private readonly array $fieldErrors = [])
    {
        parent::__construct($message);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($message, [$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

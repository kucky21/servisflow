<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Údaje servisní společnosti pro hlavičky dokumentů.
 */
final readonly class CompanyProfile
{
    public function __construct(
        public string $name,
        public string $street,
        public string $city,
        public string $zip,
        public string $ico,
        public string $dic,
        public string $phone,
        public string $email,
        public string $web,
        public string $bankAccount,
        public string $documentFooter,
    ) {
    }

    public function getAddressLine(): string
    {
        return trim($this->street . ', ' . $this->zip . ' ' . $this->city, ', ');
    }
}

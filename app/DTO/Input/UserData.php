<?php

declare(strict_types=1);

namespace App\DTO\Input;

use App\Model\Enum\UserRole;

final readonly class UserData
{
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public UserRole $role,
        public bool $isActive,
        #[\SensitiveParameter]
        public ?string $password = null,
    ) {
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public static function fromArray(iterable $values): self
    {
        $v = Normalizer::toArray($values);

        return new self(
            email: mb_strtolower(Normalizer::string($v['email'] ?? '')),
            firstName: Normalizer::string($v['first_name'] ?? ''),
            lastName: Normalizer::string($v['last_name'] ?? ''),
            phone: Normalizer::nullableString($v['phone'] ?? null),
            role: UserRole::tryFrom((string) ($v['role'] ?? '')) ?? UserRole::Technician,
            isActive: (bool) ($v['is_active'] ?? true),
            password: Normalizer::nullableString($v['password'] ?? null),
        );
    }
}

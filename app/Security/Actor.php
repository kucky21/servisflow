<?php

declare(strict_types=1);

namespace App\Security;

use App\Model\Enum\UserRole;

/**
 * Kdo provádí operaci. Služby přijímají Actor explicitně – nezávisí tak na HTTP session
 * a lze je volat z konzole i testů.
 */
final readonly class Actor
{
    public function __construct(
        public int $id,
        public UserRole $role,
        public string $name,
    ) {
    }

    public function isTechnician(): bool
    {
        return $this->role === UserRole::Technician;
    }

    public function isManagerial(): bool
    {
        return $this->role->isManagerial();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}

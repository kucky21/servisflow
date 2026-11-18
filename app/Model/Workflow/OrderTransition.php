<?php

declare(strict_types=1);

namespace App\Model\Workflow;

use App\Model\Enum\OrderStatus;
use App\Model\Enum\UserRole;

/**
 * Definice jednoho povoleného přechodu stavového automatu zakázky.
 */
final readonly class OrderTransition
{
    /**
     * @param list<UserRole> $roles Role, které smí přechod provést
     */
    public function __construct(
        public OrderStatus $from,
        public OrderStatus $to,
        public array $roles,
        public string $label,
        public bool $requiresNote = false,
        public string $style = 'primary',
        public ?string $notePrompt = null,
    ) {
    }

    public function isAllowedFor(UserRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function getKey(): string
    {
        return $this->from->value . '>' . $this->to->value;
    }
}

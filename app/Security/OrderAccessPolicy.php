<?php

declare(strict_types=1);

namespace App\Security;

use App\Exceptions\AccessDeniedException;
use App\Model\Enum\OrderStatus;
use Nette\Database\Table\ActiveRow;

/**
 * Oprávnění na úrovni konkrétní zakázky.
 *
 *  - Vedoucí a administrátor: všechny zakázky.
 *  - Technik: pouze zakázky, které jsou mu přiřazené.
 */
final class OrderAccessPolicy
{
    public function canView(Actor $actor, ?int $assignedTechnicianId): bool
    {
        if ($actor->isManagerial()) {
            return true;
        }

        return $assignedTechnicianId !== null && $assignedTechnicianId === $actor->id;
    }

    /**
     * Evidence práce, materiálu, diagnostiky – vyžaduje přístup a rozpracovaný stav.
     */
    public function canWork(Actor $actor, ?int $assignedTechnicianId, OrderStatus $status): bool
    {
        return $status->allowsWork() && $this->canView($actor, $assignedTechnicianId);
    }

    /**
     * Úprava obchodních údajů zakázky (ceny, sleva, termín, priorita).
     */
    public function canManage(Actor $actor): bool
    {
        return $actor->isManagerial();
    }

    public function canViewRow(Actor $actor, ActiveRow $order): bool
    {
        return $this->canView($actor, self::technicianId($order));
    }

    public function canWorkOnRow(Actor $actor, ActiveRow $order): bool
    {
        return $this->canWork($actor, self::technicianId($order), OrderStatus::from((string) $order->status));
    }

    public function assertCanView(Actor $actor, ActiveRow $order): void
    {
        if (!$this->canViewRow($actor, $order)) {
            throw new AccessDeniedException('K této zakázce nemáte přístup.');
        }
    }

    public function assertCanManage(Actor $actor): void
    {
        if (!$this->canManage($actor)) {
            throw new AccessDeniedException('Tuto operaci může provést pouze vedoucí servisu.');
        }
    }

    private static function technicianId(ActiveRow $order): ?int
    {
        $id = $order->assigned_technician_id;

        return $id === null ? null : (int) $id;
    }
}

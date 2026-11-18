<?php

declare(strict_types=1);

namespace App\Model\Workflow;

use App\Exceptions\InvalidTransitionException;
use App\Model\Enum\OrderStatus;
use App\Model\Enum\UserRole;

/**
 * Stavový automat servisní zakázky.
 *
 *   Přijato ─► Diagnostika ─► Čeká na schválení ─► V opravě ◄─► Čeká na díly
 *                    │                                 │
 *                    └──────────(vedoucí)─────────────►│
 *                                                      ▼
 *                         Předáno zákazníkovi ◄── Dokončeno ──(reklamace)──► V opravě
 *
 *   Zrušit lze kteroukoli rozpracovanou zakázku (pouze vedoucí, s povinným důvodem).
 *   Předáno a Zrušeno jsou konečné stavy.
 *
 * Třída definuje pouze graf a oprávnění rolí. Podmínky závislé na datech zakázky
 * (diagnostika, výkazy…) kontroluje {@see \App\Services\OrderWorkflowService}.
 */
final class OrderStateMachine
{
    /** @var array<string, OrderTransition> */
    private array $transitions = [];

    public function __construct()
    {
        $all = [UserRole::Admin, UserRole::Manager, UserRole::Technician];
        $managers = [UserRole::Admin, UserRole::Manager];

        $this->add(new OrderTransition(OrderStatus::Received, OrderStatus::Diagnostics, $all, 'Zahájit diagnostiku', style: 'primary'));
        $this->add(new OrderTransition(OrderStatus::Diagnostics, OrderStatus::AwaitingApproval, $all, 'Odeslat ke schválení', style: 'warning'));
        $this->add(new OrderTransition(OrderStatus::Diagnostics, OrderStatus::InRepair, $managers, 'Rovnou opravit', style: 'primary', notePrompt: 'Např. záruční oprava nebo předem schválený limit'));
        $this->add(new OrderTransition(OrderStatus::AwaitingApproval, OrderStatus::InRepair, $managers, 'Zákazník schválil', style: 'success', notePrompt: 'Jak a kdy zákazník schválil (telefon, e-mail…)'));
        $this->add(new OrderTransition(OrderStatus::InRepair, OrderStatus::AwaitingParts, $all, 'Čekat na díly', style: 'warning', notePrompt: 'Které díly a odkud se objednávají'));
        $this->add(new OrderTransition(OrderStatus::AwaitingParts, OrderStatus::InRepair, $all, 'Díly dorazily', style: 'primary'));
        $this->add(new OrderTransition(OrderStatus::InRepair, OrderStatus::Completed, $all, 'Dokončit opravu', style: 'success'));
        $this->add(new OrderTransition(OrderStatus::Completed, OrderStatus::InRepair, $managers, 'Vrátit do opravy', requiresNote: true, style: 'secondary', notePrompt: 'Důvod vrácení (reklamace, nalezená závada…)'));
        $this->add(new OrderTransition(OrderStatus::Completed, OrderStatus::HandedOver, $managers, 'Předat zákazníkovi', style: 'success', notePrompt: 'Kdo zařízení převzal'));

        foreach ([OrderStatus::Received, OrderStatus::Diagnostics, OrderStatus::AwaitingApproval, OrderStatus::InRepair, OrderStatus::AwaitingParts] as $from) {
            $this->add(new OrderTransition(
                $from,
                OrderStatus::Cancelled,
                $managers,
                $from === OrderStatus::AwaitingApproval ? 'Zákazník odmítl / zrušit' : 'Zrušit zakázku',
                requiresNote: true,
                style: 'danger',
                notePrompt: 'Důvod zrušení',
            ));
        }
    }

    private function add(OrderTransition $transition): void
    {
        $this->transitions[$transition->getKey()] = $transition;
    }

    public function find(OrderStatus $from, OrderStatus $to): ?OrderTransition
    {
        return $this->transitions[$from->value . '>' . $to->value] ?? null;
    }

    /**
     * Vrátí přechod, pokud je povolen grafem i rolí; jinak vyhodí výjimku.
     */
    public function assertAllowed(OrderStatus $from, OrderStatus $to, UserRole $role): OrderTransition
    {
        $transition = $this->find($from, $to);
        if ($transition === null) {
            throw InvalidTransitionException::notAllowed($from, $to);
        }
        if (!$transition->isAllowedFor($role)) {
            throw InvalidTransitionException::roleNotAllowed($from, $to);
        }

        return $transition;
    }

    public function canTransition(OrderStatus $from, OrderStatus $to, UserRole $role): bool
    {
        return $this->find($from, $to)?->isAllowedFor($role) ?? false;
    }

    /**
     * Přechody dostupné pro danou roli ze zadaného stavu.
     *
     * @return list<OrderTransition>
     */
    public function getAvailableTransitions(OrderStatus $from, UserRole $role): array
    {
        return array_values(array_filter(
            $this->transitions,
            static fn (OrderTransition $t): bool => $t->from === $from && $t->isAllowedFor($role),
        ));
    }

    /**
     * @return list<OrderTransition>
     */
    public function getAllTransitions(): array
    {
        return array_values($this->transitions);
    }
}

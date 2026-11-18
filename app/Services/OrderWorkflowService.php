<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidTransitionException;
use App\Model\Enum\OrderStatus;
use App\Model\Workflow\OrderStateMachine;
use App\Model\Workflow\OrderTransition;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\WorkLogRepository;
use App\Security\Actor;
use App\Security\OrderAccessPolicy;
use App\Services\Pricing\OrderPricingService;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

/**
 * Řízení životního cyklu zakázky.
 *
 * Každá změna stavu probíhá v jedné transakci:
 *   zámek zakázky → kontrola přístupu → kontrola grafu a role → kontrola podmínek
 *   → vedlejší efekty (časová razítka, finální cena, vrácení materiálu) → historie → audit.
 */
final class OrderWorkflowService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly OrderStateMachine $stateMachine,
        private readonly ServiceOrderRepository $orders,
        private readonly StatusHistoryRepository $history,
        private readonly WorkLogRepository $workLogs,
        private readonly OrderPricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly OrderAccessPolicy $accessPolicy,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws InvalidTransitionException
     */
    public function changeStatus(int $orderId, OrderStatus $target, Actor $actor, ?string $note = null): void
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        $this->db->transaction(function () use ($orderId, $target, $actor, $note): void {
            $order = $this->orders->getForUpdate($orderId);
            $this->accessPolicy->assertCanView($actor, $order);

            $current = OrderStatus::from((string) $order->status);
            $transition = $this->stateMachine->assertAllowed($current, $target, $actor->role);
            $this->checkGuards($order, $transition, $note);

            $changes = $this->applySideEffects($order, $transition, $actor, $note);
            $changes['status'] = $target->value;
            $this->orders->update($orderId, $changes);

            $this->history->insert([
                'service_order_id' => $orderId,
                'from_status' => $current->value,
                'to_status' => $target->value,
                'changed_by_id' => $actor->id,
                'note' => $note,
            ]);

            $this->auditLogger->log('order.status_changed', 'service_order', $orderId, [
                'status' => ['old' => $current->value, 'new' => $target->value],
                'note' => $note,
            ], $actor);
        });
    }

    /**
     * Přechody, které aktér smí na zakázce provést (pro zobrazení tlačítek v UI).
     *
     * @return list<OrderTransition>
     */
    public function getAvailableTransitions(ActiveRow $order, Actor $actor): array
    {
        if (!$this->accessPolicy->canViewRow($actor, $order)) {
            return [];
        }

        return $this->stateMachine->getAvailableTransitions(OrderStatus::from((string) $order->status), $actor->role);
    }

    /**
     * Podmínky přechodu, které nelze vyjádřit grafem – závisí na datech zakázky.
     * Vrací seznam nesplněných podmínek (pro UI i pro kontrolu).
     *
     * @return list<string>
     */
    public function getUnmetConditions(ActiveRow $order, OrderTransition $transition): array
    {
        $problems = [];
        $diagnosis = trim((string) $order->diagnosis);

        switch ($transition->to) {
            case OrderStatus::Diagnostics:
                if ($order->assigned_technician_id === null) {
                    $problems[] = 'Zakázka musí mít přiřazeného technika.';
                }
                break;

            case OrderStatus::AwaitingApproval:
                if ($diagnosis === '') {
                    $problems[] = 'Vyplňte diagnostiku.';
                }
                if ($order->estimated_price === null) {
                    $problems[] = 'Zadejte odhadovanou cenu pro zákazníka.';
                }
                break;

            case OrderStatus::InRepair:
                if ($transition->from === OrderStatus::Diagnostics && $diagnosis === '') {
                    $problems[] = 'Před zahájením opravy vyplňte diagnostiku.';
                }
                break;

            case OrderStatus::Completed:
                if ($diagnosis === '') {
                    $problems[] = 'Vyplňte diagnostiku / popis provedené opravy.';
                }
                if ($this->workLogs->countByOrder((int) $order->id) === 0) {
                    $problems[] = 'Zakázka musí mít vykázanou alespoň jednu práci.';
                }
                break;

            default:
                break;
        }

        return $problems;
    }

    private function checkGuards(ActiveRow $order, OrderTransition $transition, ?string $note): void
    {
        if ($transition->requiresNote && $note === null) {
            throw InvalidTransitionException::guardFailed(
                sprintf('Pro přechod „%s“ je nutné uvést důvod.', $transition->label),
            );
        }

        $problems = $this->getUnmetConditions($order, $transition);
        if ($problems !== []) {
            throw InvalidTransitionException::guardFailed(implode(' ', $problems));
        }
    }

    /**
     * @return array<string, mixed> Hodnoty k uložení do zakázky
     */
    private function applySideEffects(ActiveRow $order, OrderTransition $transition, Actor $actor, ?string $note): array
    {
        $now = new \DateTimeImmutable();
        $orderId = (int) $order->id;

        return match ($transition->to) {
            // Dokončení: zafixuje finální cenu z aktuálních výkazů a materiálu
            OrderStatus::Completed => [
                'completed_at' => $now,
                'final_price' => $this->pricing->getBreakdownForOrder($order)->total->toDecimalString(),
            ],

            // Předání: pouze z dokončené zakázky (zajišťuje graf), zaznamená čas předání
            OrderStatus::HandedOver => [
                'handed_over_at' => $now,
            ],

            // Zrušení: vrácení vydaného materiálu na sklad
            OrderStatus::Cancelled => (function () use ($orderId, $actor, $order, $note, $now): array {
                $this->inventory->returnAllMaterialsForOrder(
                    $orderId,
                    $actor,
                    'Zrušení zakázky ' . $order->order_number,
                );

                return [
                    'cancelled_at' => $now,
                    'cancellation_reason' => $note,
                ];
            })(),

            // Znovuotevření dokončené zakázky: finální cena se přepočítá při dalším dokončení
            OrderStatus::InRepair => $transition->from === OrderStatus::Completed
                ? ['completed_at' => null, 'final_price' => null]
                : [],

            default => [],
        };
    }
}

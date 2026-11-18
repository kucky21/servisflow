<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\InvalidTransitionException;
use App\Model\Enum\OrderStatus;
use App\Services\InventoryService;
use App\Services\OrderWorkflowService;
use App\Services\ServiceOrderService;

final class OrderWorkflowServiceTest extends IntegrationTestCase
{
    private OrderWorkflowService $workflow;
    private ServiceOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = $this->service(OrderWorkflowService::class);
        $this->orders = $this->service(ServiceOrderService::class);
    }

    public function testFullLifecycleWithHistoryAndFinalPrice(): void
    {
        $itemId = $this->createItem(quantity: 4, sale: '1500.00');
        $orderId = $this->createOrder(discount: '10');

        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $this->orders->updateDiagnosis($orderId, 'Vadné SSD, nutná výměna.', '3500', $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::AwaitingApproval, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager, 'Schváleno telefonicky');
        $this->logWork($orderId, 90);
        $this->service(InventoryService::class)->issueToOrder($orderId, $itemId, 1, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::Completed, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::HandedOver, $this->manager, 'Převzal osobně');

        $order = $this->orderRow($orderId);
        self::assertSame('handed_over', $order->status);
        self::assertNotNull($order->completed_at);
        self::assertNotNull($order->handed_over_at);
        // práce 1,5 h × 1000 = 1500 + materiál 1500 = 3000; sleva 10 % = 2700; DPH 21 % = 567 → 3267
        self::assertSame('3267.00', (string) $order->final_price);

        $history = $this->db->table('service_order_status_history')
            ->where('service_order_id', $orderId)->order('id')->fetchPairs(null, 'to_status');
        self::assertSame(['received', 'diagnostics', 'awaiting_approval', 'in_repair', 'completed', 'handed_over'], array_values($history));
        self::assertSame(5, $this->db->table('audit_logs')->where('action', 'order.status_changed')->where('entity_id', $orderId)->count('*'));
    }

    public function testCannotSkipStates(): void
    {
        $orderId = $this->createOrder();

        $this->expectException(InvalidTransitionException::class);
        $this->workflow->changeStatus($orderId, OrderStatus::Completed, $this->manager);
    }

    public function testFailedTransitionDoesNotChangeAnything(): void
    {
        $orderId = $this->createOrder();
        try {
            $this->workflow->changeStatus($orderId, OrderStatus::HandedOver, $this->admin);
        } catch (InvalidTransitionException) {
        }

        self::assertSame('received', $this->orderRow($orderId)->status);
        self::assertSame(1, $this->db->table('service_order_status_history')->where('service_order_id', $orderId)->count('*'));
    }

    public function testTechnicianCannotApproveOnBehalfOfCustomer(): void
    {
        $orderId = $this->createOrder();
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $this->orders->updateDiagnosis($orderId, 'Diagnostika', '1000', $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::AwaitingApproval, $this->technician);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Vaše role nemůže');
        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->technician);
    }

    public function testTechnicianCannotTouchOrderAssignedToSomeoneElse(): void
    {
        $orderId = $this->createOrder($this->otherTechnician->id);

        $this->expectException(AccessDeniedException::class);
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
    }

    public function testDiagnosticsRequiresAssignedTechnician(): void
    {
        $orderId = $this->createOrder();
        $this->orders->assignTechnician($orderId, null, $this->manager);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('přiřazeného technika');
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->manager);
    }

    public function testApprovalRequiresDiagnosisAndEstimate(): void
    {
        $orderId = $this->createOrder();
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Vyplňte diagnostiku');
        $this->workflow->changeStatus($orderId, OrderStatus::AwaitingApproval, $this->technician);
    }

    public function testCompletionRequiresLoggedWork(): void
    {
        $orderId = $this->createOrder();
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $this->orders->updateDiagnosis($orderId, 'Diagnostika', null, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('alespoň jednu práci');
        $this->workflow->changeStatus($orderId, OrderStatus::Completed, $this->technician);
    }

    public function testCancellationRequiresReasonAndReturnsMaterial(): void
    {
        $itemId = $this->createItem(quantity: 5);
        $orderId = $this->createOrder();
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $this->orders->updateDiagnosis($orderId, 'Diagnostika', null, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager);
        $this->service(InventoryService::class)->issueToOrder($orderId, $itemId, 3, $this->technician);
        self::assertSame(2, $this->itemQuantity($itemId));

        try {
            $this->workflow->changeStatus($orderId, OrderStatus::Cancelled, $this->manager);
            self::fail('Zrušení bez důvodu musí selhat.');
        } catch (InvalidTransitionException $e) {
            self::assertStringContainsString('důvod', $e->getMessage());
        }

        $this->workflow->changeStatus($orderId, OrderStatus::Cancelled, $this->manager, 'Zákazník odmítl cenu');

        $order = $this->orderRow($orderId);
        self::assertSame('cancelled', $order->status);
        self::assertSame('Zákazník odmítl cenu', $order->cancellation_reason);
        self::assertNotNull($order->cancelled_at);
        self::assertSame(5, $this->itemQuantity($itemId));
        self::assertSame(0, $this->db->table('service_order_materials')->where('service_order_id', $orderId)->count('*'));
    }

    public function testReopeningCompletedOrderClearsFinalPrice(): void
    {
        $orderId = $this->createOrder();
        $this->workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $this->orders->updateDiagnosis($orderId, 'Diagnostika', null, $this->technician);
        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager);
        $this->logWork($orderId, 60);
        $this->workflow->changeStatus($orderId, OrderStatus::Completed, $this->technician);
        self::assertSame('1210.00', (string) $this->orderRow($orderId)->final_price);

        $this->workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager, 'Reklamace – závada se vrátila');

        $order = $this->orderRow($orderId);
        self::assertNull($order->final_price);
        self::assertNull($order->completed_at);
    }

    public function testAvailableTransitionsDependOnRole(): void
    {
        $orderId = $this->createOrder();
        $order = $this->orderRow($orderId);

        $forTechnician = array_map(fn ($t) => $t->to, $this->workflow->getAvailableTransitions($order, $this->technician));
        $forManager = array_map(fn ($t) => $t->to, $this->workflow->getAvailableTransitions($order, $this->manager));

        self::assertSame([OrderStatus::Diagnostics], $forTechnician);
        self::assertContains(OrderStatus::Cancelled, $forManager);
        self::assertSame([], $this->workflow->getAvailableTransitions($order, $this->otherTechnician));
    }
}

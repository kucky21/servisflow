<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderLockedException;
use App\Model\Enum\OrderStatus;
use App\Services\InventoryService;
use App\Services\OrderWorkflowService;
use App\Services\Pricing\OrderPricingService;
use Nette\Database\DriverException;

final class InventoryServiceTest extends IntegrationTestCase
{
    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = $this->service(InventoryService::class);
    }

    public function testInitialQuantityIsRecordedAsReceipt(): void
    {
        $itemId = $this->createItem(quantity: 7);

        self::assertSame(7, $this->itemQuantity($itemId));
        $movement = $this->db->table('stock_movements')->where('inventory_item_id', $itemId)->fetch();
        self::assertNotNull($movement);
        self::assertSame('receipt', $movement->type);
        self::assertSame(7, $movement->quantity_after);
    }

    public function testReceiptIncreasesStockAndUpdatesPurchasePrice(): void
    {
        $itemId = $this->createItem(quantity: 2);

        $newQuantity = $this->inventory->receive($itemId, 10, '950.50', 'Faktura 2026/1', $this->manager);

        self::assertSame(12, $newQuantity);
        self::assertSame(12, $this->itemQuantity($itemId));
        self::assertSame('950.50', (string) $this->db->table('inventory_items')->get($itemId)?->purchase_price);
        self::assertSame(2, $this->db->table('stock_movements')->where('inventory_item_id', $itemId)->count('*'));
    }

    public function testIssueToOrderDecreasesStockAndStoresHistoricPrices(): void
    {
        $itemId = $this->createItem(quantity: 5, sale: '1500.00');
        $orderId = $this->createOrder();

        $materialId = $this->inventory->issueToOrder($orderId, $itemId, 2, $this->technician);

        self::assertSame(3, $this->itemQuantity($itemId));
        $material = $this->db->table('service_order_materials')->get($materialId);
        self::assertNotNull($material);
        self::assertSame('1500.00', (string) $material->unit_price);
        self::assertSame('1000.00', (string) $material->unit_purchase_price);

        $movement = $this->db->table('stock_movements')->get((int) $material->stock_movement_id);
        self::assertNotNull($movement);
        self::assertSame(-2, $movement->quantity);
        self::assertSame(3, $movement->quantity_after);
        self::assertSame($orderId, $movement->service_order_id);
    }

    public function testCannotIssueMoreThanInStock(): void
    {
        $itemId = $this->createItem(quantity: 3);
        $orderId = $this->createOrder();

        try {
            $this->inventory->issueToOrder($orderId, $itemId, 4, $this->technician);
            self::fail('Očekávána výjimka InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            self::assertSame(4, $e->requested);
            self::assertSame(3, $e->available);
        }

        // Transakce se vrátila – žádný pohyb ani materiál
        self::assertSame(3, $this->itemQuantity($itemId));
        self::assertSame(0, $this->db->table('service_order_materials')->count('*'));
        self::assertSame(1, $this->db->table('stock_movements')->where('inventory_item_id', $itemId)->count('*'));
    }

    public function testDatabaseConstraintPreventsNegativeStock(): void
    {
        $itemId = $this->createItem(quantity: 1);

        $this->expectException(DriverException::class);
        $this->db->query('UPDATE inventory_items SET quantity = quantity - 5 WHERE id = ?', $itemId);
    }

    public function testSequentialIssuesNeverOversell(): void
    {
        // Simulace souběhu: dvě operace o poslední kusy – druhá musí selhat
        $itemId = $this->createItem(quantity: 3);
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->inventory->issueToOrder($first, $itemId, 2, $this->technician);

        $this->expectException(InsufficientStockException::class);
        $this->inventory->issueToOrder($second, $itemId, 2, $this->technician);
    }

    public function testRemovingMaterialReturnsItToStock(): void
    {
        $itemId = $this->createItem(quantity: 5);
        $orderId = $this->createOrder();
        $materialId = $this->inventory->issueToOrder($orderId, $itemId, 2, $this->technician);

        $this->inventory->removeMaterialFromOrder($materialId, $this->technician);

        self::assertSame(5, $this->itemQuantity($itemId));
        self::assertNull($this->db->table('service_order_materials')->get($materialId));
        self::assertSame(1, $this->db->table('stock_movements')->where('inventory_item_id', $itemId)->where('type', 'return')->count('*'));
    }

    public function testPriceListChangeDoesNotAffectExistingOrder(): void
    {
        $itemId = $this->createItem(quantity: 5, sale: '1500.00');
        $orderId = $this->createOrder();
        $this->inventory->issueToOrder($orderId, $itemId, 1, $this->technician);

        $this->db->table('inventory_items')->where('id', $itemId)->update(['sale_price' => '9999.00']);

        $breakdown = $this->service(OrderPricingService::class)->getBreakdown($orderId);
        self::assertSame('1500.00', $breakdown->materialTotal->toDecimalString());
    }

    public function testTechnicianCannotIssueToOrderOfAnotherTechnician(): void
    {
        $itemId = $this->createItem(quantity: 5);
        $orderId = $this->createOrder($this->otherTechnician->id);

        $this->expectException(AccessDeniedException::class);
        $this->inventory->issueToOrder($orderId, $itemId, 1, $this->technician);
    }

    public function testCannotIssueToCompletedOrder(): void
    {
        $itemId = $this->createItem(quantity: 5);
        $orderId = $this->createOrder();
        $workflow = $this->service(OrderWorkflowService::class);
        $this->db->table('service_orders')->where('id', $orderId)->update(['diagnosis' => 'Vadný disk']);
        $workflow->changeStatus($orderId, OrderStatus::Diagnostics, $this->technician);
        $workflow->changeStatus($orderId, OrderStatus::InRepair, $this->manager);
        $this->logWork($orderId);
        $workflow->changeStatus($orderId, OrderStatus::Completed, $this->technician);

        $this->expectException(OrderLockedException::class);
        $this->inventory->issueToOrder($orderId, $itemId, 1, $this->manager);
    }

    public function testInventoryAdjustmentRequiresReason(): void
    {
        $itemId = $this->createItem(quantity: 5);

        $this->assertSame(3, $this->inventory->adjust($itemId, 3, 'Inventura – poškozené kusy', $this->manager));
        $adjustment = $this->db->table('stock_movements')->where('type', 'adjustment')->fetch();
        self::assertSame(-2, $adjustment?->quantity);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->inventory->adjust($itemId, 1, '  ', $this->manager);
    }

    public function testStockAlwaysEqualsSumOfMovements(): void
    {
        $itemId = $this->createItem(quantity: 10);
        $orderId = $this->createOrder();
        $this->inventory->issueToOrder($orderId, $itemId, 3, $this->technician);
        $materialId = $this->inventory->issueToOrder($orderId, $itemId, 2, $this->technician);
        $this->inventory->removeMaterialFromOrder($materialId, $this->technician);
        $this->inventory->receive($itemId, 4, null, null, $this->manager);
        $this->inventory->adjust($itemId, 10, 'Inventura', $this->manager);

        $sum = (int) $this->db->table('stock_movements')->where('inventory_item_id', $itemId)->sum('quantity');
        self::assertSame($this->itemQuantity($itemId), $sum);
    }

    public function testTechnicianCannotManageInventory(): void
    {
        $itemId = $this->createItem(quantity: 1);

        $this->expectException(AccessDeniedException::class);
        $this->inventory->receive($itemId, 5, null, null, $this->technician);
    }
}

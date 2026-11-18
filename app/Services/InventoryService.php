<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Input\InventoryItemData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderLockedException;
use App\Exceptions\ValidationException;
use App\Model\Enum\OrderStatus;
use App\Model\Enum\StockMovementType;
use App\Model\Money\Money;
use App\Repositories\InventoryItemRepository;
use App\Repositories\OrderMaterialRepository;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\StockMovementRepository;
use App\Security\Actor;
use App\Security\OrderAccessPolicy;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

/**
 * Skladové operace. Každá změna množství:
 *  - probíhá v transakci,
 *  - zamyká řádek položky (SELECT … FOR UPDATE) a odečítá podmíněným UPDATE (quantity >= n),
 *  - zapisuje skladový pohyb se stavem po pohybu,
 *  - je chráněna CHECK (quantity >= 0) v databázi jako poslední pojistkou.
 *
 * Pořadí zamykání je vždy: zakázka → skladová položka (prevence deadlocků).
 */
final class InventoryService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly InventoryItemRepository $items,
        private readonly StockMovementRepository $movements,
        private readonly OrderMaterialRepository $orderMaterials,
        private readonly ServiceOrderRepository $orders,
        private readonly OrderAccessPolicy $accessPolicy,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ------------------------------------------------------------------
    // Správa položek
    // ------------------------------------------------------------------

    public function createItem(InventoryItemData $data, Actor $actor): int
    {
        $this->assertCanManage($actor);
        $this->validateItem($data, null);

        return $this->db->transaction(function () use ($data, $actor): int {
            $row = $this->items->insert([
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $data->description,
                'unit' => $data->unit,
                'purchase_price' => Money::of($data->purchasePrice)->toDecimalString(),
                'sale_price' => Money::of($data->salePrice)->toDecimalString(),
                'min_quantity' => $data->minQuantity,
                'quantity' => 0,
                'is_active' => $data->isActive,
            ]);
            $id = (int) $row->id;
            $this->auditLogger->log('inventory.created', 'inventory_item', $id, ['sku' => $data->sku, 'name' => $data->name], $actor);

            if ($data->initialQuantity > 0) {
                $this->receive($id, $data->initialQuantity, null, 'Počáteční stav', $actor);
            }

            return $id;
        });
    }

    public function updateItem(int $id, InventoryItemData $data, Actor $actor): void
    {
        $this->assertCanManage($actor);
        $this->validateItem($data, $id);

        $this->db->transaction(function () use ($id, $data, $actor): void {
            $current = $this->items->getForUpdate($id);
            $row = [
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $data->description,
                'unit' => $data->unit,
                'purchase_price' => Money::of($data->purchasePrice)->toDecimalString(),
                'sale_price' => Money::of($data->salePrice)->toDecimalString(),
                'min_quantity' => $data->minQuantity,
                'is_active' => $data->isActive,
            ];
            $diff = AuditLogger::diff($current->toArray(), $row);
            if ($diff === []) {
                return;
            }
            $this->items->update($id, $row);
            $this->auditLogger->log('inventory.updated', 'inventory_item', $id, $diff, $actor);
        });
    }

    /**
     * Položku s historií pohybů nelze smazat – lze ji pouze deaktivovat.
     */
    public function deleteItem(int $id, Actor $actor): void
    {
        $this->assertCanManage($actor);

        $this->db->transaction(function () use ($id, $actor): void {
            $item = $this->items->getForUpdate($id);
            if ($this->items->hasMovements($id)) {
                throw new DomainException('Položku se skladovými pohyby nelze smazat. Můžete ji deaktivovat.');
            }
            $this->items->delete($id);
            $this->auditLogger->log('inventory.deleted', 'inventory_item', $id, ['sku' => (string) $item->sku], $actor);
        });
    }

    // ------------------------------------------------------------------
    // Pohyby
    // ------------------------------------------------------------------

    /**
     * Příjem materiálu na sklad. Volitelně aktualizuje nákupní cenu.
     */
    public function receive(int $itemId, int $quantity, ?string $unitPrice, ?string $note, Actor $actor): int
    {
        $this->assertCanManage($actor);
        if ($quantity <= 0 || $quantity > 100000) {
            throw ValidationException::forField('quantity', 'Množství příjmu musí být kladné celé číslo.');
        }
        $price = Money::ofNullable($unitPrice);
        if ($price !== null && $price->isNegative()) {
            throw ValidationException::forField('unit_price', 'Cena nesmí být záporná.');
        }

        return $this->db->transaction(function () use ($itemId, $quantity, $price, $note, $actor): int {
            $item = $this->items->getForUpdate($itemId);
            $newQuantity = $this->items->increaseQuantity($itemId, $quantity);
            if ($price !== null) {
                $this->items->update($itemId, ['purchase_price' => $price->toDecimalString()]);
            }

            $this->movements->insert([
                'inventory_item_id' => $itemId,
                'type' => StockMovementType::Receipt->value,
                'quantity' => $quantity,
                'quantity_after' => $newQuantity,
                'unit_price' => ($price ?? Money::of((string) $item->purchase_price))->toDecimalString(),
                'user_id' => $actor->id,
                'note' => $note,
            ]);
            $this->auditLogger->log('inventory.received', 'inventory_item', $itemId, [
                'quantity' => $quantity,
                'quantity_after' => $newQuantity,
            ], $actor);

            return $newQuantity;
        });
    }

    /**
     * Inventurní úprava na skutečný stav (s povinným zdůvodněním).
     */
    public function adjust(int $itemId, int $actualQuantity, string $note, Actor $actor): int
    {
        $this->assertCanManage($actor);
        if ($actualQuantity < 0) {
            throw ValidationException::forField('quantity', 'Skutečný stav nemůže být záporný.');
        }
        if (trim($note) === '') {
            throw ValidationException::forField('note', 'U inventurní úpravy uveďte důvod.');
        }

        return $this->db->transaction(function () use ($itemId, $actualQuantity, $note, $actor): int {
            $item = $this->items->getForUpdate($itemId);
            $delta = $actualQuantity - (int) $item->quantity;
            if ($delta === 0) {
                return $actualQuantity;
            }

            $newQuantity = $this->items->setQuantity($itemId, $actualQuantity);
            $this->movements->insert([
                'inventory_item_id' => $itemId,
                'type' => StockMovementType::Adjustment->value,
                'quantity' => $delta,
                'quantity_after' => $newQuantity,
                'unit_price' => (string) $item->purchase_price,
                'user_id' => $actor->id,
                'note' => trim($note),
            ]);
            $this->auditLogger->log('inventory.adjusted', 'inventory_item', $itemId, [
                'quantity' => ['old' => (int) $item->quantity, 'new' => $newQuantity],
                'note' => trim($note),
            ], $actor);

            return $newQuantity;
        });
    }

    /**
     * Výdej materiálu k zakázce. Uloží historickou prodejní i nákupní cenu.
     *
     * @throws InsufficientStockException
     */
    public function issueToOrder(int $orderId, int $itemId, int $quantity, Actor $actor): int
    {
        if ($quantity <= 0 || $quantity > 1000) {
            throw ValidationException::forField('quantity', 'Množství musí být kladné celé číslo.');
        }

        return $this->db->transaction(function () use ($orderId, $itemId, $quantity, $actor): int {
            $order = $this->lockOrderForWork($orderId, $actor, 'Výdej materiálu');
            $item = $this->items->getForUpdate($itemId);

            if (!(bool) $item->is_active) {
                throw new DomainException(sprintf('Položka %s je neaktivní a nelze ji vydat.', $item->sku));
            }

            $newQuantity = $this->items->decreaseQuantity($itemId, $quantity);
            if ($newQuantity === null) {
                throw new InsufficientStockException((string) $item->sku, $quantity, (int) $item->quantity);
            }

            $movement = $this->movements->insert([
                'inventory_item_id' => $itemId,
                'type' => StockMovementType::Issue->value,
                'quantity' => -$quantity,
                'quantity_after' => $newQuantity,
                'unit_price' => (string) $item->sale_price,
                'service_order_id' => $orderId,
                'user_id' => $actor->id,
                'note' => 'Výdej na zakázku ' . $order->order_number,
            ]);

            $material = $this->orderMaterials->insert([
                'service_order_id' => $orderId,
                'inventory_item_id' => $itemId,
                'stock_movement_id' => (int) $movement->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'unit' => (string) $item->unit,
                'quantity' => $quantity,
                'unit_price' => (string) $item->sale_price,
                'unit_purchase_price' => (string) $item->purchase_price,
                'created_by_id' => $actor->id,
            ]);

            $this->auditLogger->log('order.material_added', 'service_order', $orderId, [
                'sku' => (string) $item->sku,
                'quantity' => $quantity,
                'unit_price' => (string) $item->sale_price,
            ], $actor);

            return (int) $material->id;
        });
    }

    /**
     * Odebrání materiálu ze zakázky – materiál se vrací na sklad.
     */
    public function removeMaterialFromOrder(int $materialId, Actor $actor): void
    {
        $this->db->transaction(function () use ($materialId, $actor): void {
            $orderId = (int) $this->orderMaterials->getById($materialId)->service_order_id;
            $order = $this->lockOrderForWork($orderId, $actor, 'Odebrání materiálu');

            // Znovu načíst až po zámku zakázky – souběžný požadavek mohl řádek mezitím odebrat.
            $material = $this->orderMaterials->findById($materialId)
                ?? throw new DomainException('Materiál již byl ze zakázky odebrán.');

            $this->returnMaterialRow($material, $actor, 'Vráceno ze zakázky ' . $order->order_number);
            $this->auditLogger->log('order.material_removed', 'service_order', $orderId, [
                'sku' => (string) $material->sku,
                'quantity' => (int) $material->quantity,
            ], $actor);
        });
    }

    /**
     * Vrátí veškerý materiál zakázky na sklad (při zrušení zakázky).
     * Volá se uvnitř transakce workflow, která již drží zámek zakázky.
     *
     * @return int Počet vrácených položek
     */
    public function returnAllMaterialsForOrder(int $orderId, Actor $actor, string $note): int
    {
        return $this->db->transaction(function () use ($orderId, $actor, $note): int {
            $count = 0;
            // Zamykání položek v pořadí podle ID – deterministické pořadí brání deadlockům
            $materials = $this->orderMaterials->findByOrder($orderId)->order('inventory_item_id')->fetchAll();
            foreach ($materials as $material) {
                $this->returnMaterialRow($material, $actor, $note);
                $count++;
            }

            return $count;
        });
    }

    private function returnMaterialRow(ActiveRow $material, Actor $actor, string $note): void
    {
        $itemId = (int) $material->inventory_item_id;
        $quantity = (int) $material->quantity;

        $this->items->getForUpdate($itemId);
        $newQuantity = $this->items->increaseQuantity($itemId, $quantity);

        $this->movements->insert([
            'inventory_item_id' => $itemId,
            'type' => StockMovementType::Return->value,
            'quantity' => $quantity,
            'quantity_after' => $newQuantity,
            'unit_price' => (string) $material->unit_price,
            'service_order_id' => (int) $material->service_order_id,
            'user_id' => $actor->id,
            'note' => $note,
        ]);

        $this->orderMaterials->delete((int) $material->id);
    }

    private function lockOrderForWork(int $orderId, Actor $actor, string $operation): ActiveRow
    {
        $order = $this->orders->getForUpdate($orderId);
        $this->accessPolicy->assertCanView($actor, $order);

        $status = OrderStatus::from((string) $order->status);
        if (!$status->allowsWork()) {
            throw OrderLockedException::forStatus($status, $operation);
        }

        return $order;
    }

    private function validateItem(InventoryItemData $data, ?int $exceptId): void
    {
        $errors = [];
        if (preg_match('/^[A-Z0-9][A-Z0-9\-_.]{1,39}$/', $data->sku) !== 1) {
            $errors['sku'] = 'SKU smí obsahovat velká písmena, číslice, pomlčky a tečky (2–40 znaků).';
        } elseif ($this->items->skuExists($data->sku, $exceptId)) {
            $errors['sku'] = 'Položka s tímto SKU již existuje.';
        }
        if ($data->name === '') {
            $errors['name'] = 'Vyplňte název položky.';
        }
        if (Money::of($data->purchasePrice)->isNegative()) {
            $errors['purchase_price'] = 'Nákupní cena nesmí být záporná.';
        }
        if (Money::of($data->salePrice)->isNegative()) {
            $errors['sale_price'] = 'Prodejní cena nesmí být záporná.';
        }

        if ($errors !== []) {
            throw new ValidationException('Formulář obsahuje chyby.', $errors);
        }
    }

    private function assertCanManage(Actor $actor): void
    {
        if (!$actor->isManagerial()) {
            throw new AccessDeniedException('Sklad může spravovat pouze vedoucí servisu.');
        }
    }
}

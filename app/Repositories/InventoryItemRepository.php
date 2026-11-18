<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\Filter\InventoryFilter;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class InventoryItemRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'inventory_items';
    }

    /** @return Selection<ActiveRow> */
    public function search(InventoryFilter $filter): Selection
    {
        $selection = $this->table();

        if (!$filter->includeInactive) {
            $selection->where('is_active', true);
        }

        if ($filter->query !== null && trim($filter->query) !== '') {
            $pattern = self::likePattern($filter->query);
            $selection->where('sku ILIKE ? OR name ILIKE ? OR description ILIKE ?', $pattern, $pattern, $pattern);
        }

        if ($filter->lowStockOnly) {
            $selection->where('quantity <= min_quantity');
        }

        return $selection->order('(quantity <= min_quantity) DESC')->order('name');
    }

    /**
     * Atomický odpis: sníží množství jen tehdy, pokud je ho dostatek.
     * Vrací nové množství, nebo null, pokud zásoba nestačí.
     */
    public function decreaseQuantity(int $id, int $quantity): ?int
    {
        $result = $this->db->query(
            'UPDATE inventory_items SET quantity = quantity - ?
             WHERE id = ? AND is_active AND quantity >= ?
             RETURNING quantity',
            $quantity,
            $id,
            $quantity,
        )->fetchField();

        return $result === null || $result === false ? null : (int) $result;
    }

    public function increaseQuantity(int $id, int $quantity): int
    {
        return (int) $this->db->query(
            'UPDATE inventory_items SET quantity = quantity + ? WHERE id = ? RETURNING quantity',
            $quantity,
            $id,
        )->fetchField();
    }

    public function setQuantity(int $id, int $quantity): int
    {
        return (int) $this->db->query(
            'UPDATE inventory_items SET quantity = ? WHERE id = ? RETURNING quantity',
            $quantity,
            $id,
        )->fetchField();
    }

    /** @return Selection<ActiveRow> */
    public function findLowStock(int $limit = 10): Selection
    {
        return $this->table()
            ->where('is_active', true)
            ->where('quantity <= min_quantity')
            ->order('quantity - min_quantity, name')
            ->limit(max(0, $limit));
    }

    public function countLowStock(): int
    {
        return $this->table()->where('is_active', true)->where('quantity <= min_quantity')->count('*');
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $selection = $this->table()->where('upper(sku) = ?', mb_strtoupper(trim($sku)));
        if ($exceptId !== null) {
            $selection->where('id != ?', $exceptId);
        }

        return $selection->count('*') > 0;
    }

    /**
     * Položky dostupné k výdeji na zakázku.
     *
     * @return array<int, string>
     */
    public function getIssueOptions(): array
    {
        $options = [];
        $rows = $this->table()->where('is_active', true)->where('quantity > 0')->order('name');
        foreach ($rows as $row) {
            $options[(int) $row->id] = sprintf('%s – %s (skladem %d %s)', $row->sku, $row->name, $row->quantity, $row->unit);
        }

        return $options;
    }

    public function hasMovements(int $id): bool
    {
        return $this->db->table('stock_movements')->where('inventory_item_id', $id)->count('*') > 0;
    }

    /**
     * Celková hodnota skladu v nákupních cenách (pro přehled).
     */
    public function getStockValue(): string
    {
        $value = $this->db->query(
            'SELECT COALESCE(SUM(quantity * purchase_price), 0)::numeric(14,2) FROM inventory_items WHERE is_active',
        )->fetchField();

        return (string) $value;
    }
}

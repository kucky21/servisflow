<?php

declare(strict_types=1);

namespace App\Repositories;

use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class StockMovementRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'stock_movements';
    }

    /** @return Selection<ActiveRow> */
    public function findByItem(int $itemId): Selection
    {
        return $this->table()->where('inventory_item_id', $itemId)->order('created_at DESC, id DESC');
    }

    /** @return Selection<ActiveRow> */
    public function findRecent(int $limit = 50): Selection
    {
        return $this->table()->order('created_at DESC, id DESC')->limit(max(0, $limit));
    }

    /**
     * Kontrolní součet: suma pohybů musí odpovídat aktuálnímu stavu položky.
     */
    public function sumForItem(int $itemId): int
    {
        return (int) $this->table()->where('inventory_item_id', $itemId)->sum('quantity');
    }
}

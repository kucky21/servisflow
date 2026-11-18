<?php

declare(strict_types=1);

namespace App\Repositories;

use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class StatusHistoryRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'service_order_status_history';
    }

    /** @return Selection<ActiveRow> */
    public function findByOrder(int $orderId): Selection
    {
        return $this->table()->where('service_order_id', $orderId)->order('created_at DESC, id DESC');
    }

    public function countByOrder(int $orderId): int
    {
        return $this->table()->where('service_order_id', $orderId)->count('*');
    }
}

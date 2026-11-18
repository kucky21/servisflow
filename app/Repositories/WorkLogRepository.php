<?php

declare(strict_types=1);

namespace App\Repositories;

use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class WorkLogRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'work_logs';
    }

    /** @return Selection<ActiveRow> */
    public function findByOrder(int $orderId): Selection
    {
        return $this->table()->where('service_order_id', $orderId)->order('work_date, id');
    }

    public function countByOrder(int $orderId): int
    {
        return $this->table()->where('service_order_id', $orderId)->count('*');
    }
}

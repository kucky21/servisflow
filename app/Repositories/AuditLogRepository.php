<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\Filter\AuditFilter;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class AuditLogRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'audit_logs';
    }

    /** @return Selection<ActiveRow> */
    public function search(AuditFilter $filter): Selection
    {
        $selection = $this->table()->order('created_at DESC, id DESC');

        if ($filter->entityType !== null && $filter->entityType !== '') {
            $selection->where('entity_type', $filter->entityType);
        }
        if ($filter->userId !== null) {
            $selection->where('user_id', $filter->userId);
        }
        if ($filter->action !== null && $filter->action !== '') {
            $selection->where('action ILIKE ?', self::likePattern($filter->action));
        }

        return $selection;
    }

    /** @return Selection<ActiveRow> */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50): Selection
    {
        return $this->table()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->order('created_at DESC, id DESC')
            ->limit(max(0, $limit));
    }

    /**
     * @return array<string, string>
     */
    public function getEntityTypes(): array
    {
        $types = $this->table()->select('DISTINCT entity_type')->order('entity_type')->fetchPairs(null, 'entity_type');

        return array_combine($types, $types);
    }
}

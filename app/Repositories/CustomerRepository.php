<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\Filter\CustomerFilter;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class CustomerRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'customers';
    }

    /** @return Selection<ActiveRow> */
    public function search(CustomerFilter $filter): Selection
    {
        $selection = $this->table();

        if ($filter->query !== null && trim($filter->query) !== '') {
            $pattern = self::likePattern($filter->query);
            $selection->where(
                'name ILIKE ? OR email ILIKE ? OR phone ILIKE ? OR ico ILIKE ? OR city ILIKE ? OR contact_person ILIKE ?',
                $pattern,
                $pattern,
                $pattern,
                $pattern,
                $pattern,
                $pattern,
            );
        }

        if ($filter->type !== null) {
            $selection->where('type', $filter->type->value);
        }

        $selection->order(match ($filter->sort) {
            'newest' => 'created_at DESC',
            'city' => 'city, name',
            default => 'name',
        });

        return $selection;
    }

    public function icoExists(string $ico, ?int $exceptId = null): bool
    {
        $selection = $this->table()->where('ico', $ico);
        if ($exceptId !== null) {
            $selection->where('id != ?', $exceptId);
        }

        return $selection->count('*') > 0;
    }

    /**
     * Hledání pro našeptávač ve formuláři zakázky.
     *
     * @return list<array{id: int, label: string, meta: string}>
     */
    public function autocomplete(string $term, int $limit = 10): array
    {
        $result = [];
        $pattern = self::likePattern($term);
        $rows = $this->table()
            ->where('name ILIKE ? OR email ILIKE ? OR phone ILIKE ? OR ico ILIKE ?', $pattern, $pattern, $pattern, $pattern)
            ->order('name')
            ->limit(max(0, $limit));

        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row->id,
                'label' => (string) $row->name,
                'meta' => implode(' · ', array_filter([(string) $row->email, (string) $row->phone, (string) $row->city])),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function getOptions(): array
    {
        return $this->table()->order('name')->fetchPairs('id', 'name');
    }

    public function countCreatedSince(\DateTimeInterface $since): int
    {
        return $this->table()->where('created_at >= ?', $since)->count('*');
    }

    public function hasOrders(int $id): bool
    {
        return $this->db->table('service_orders')->where('customer_id', $id)->count('*') > 0;
    }
}

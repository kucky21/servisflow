<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'settings';
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $values = [];
        foreach ($this->table() as $row) {
            $values[(string) $row->key] = (string) $row->value;
        }

        return $values;
    }

    public function set(string $key, string $value): void
    {
        $this->db->query(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
            $key,
            $value,
        );
    }
}

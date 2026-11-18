<?php

declare(strict_types=1);

namespace App\Repositories;

final class OrderNumberSequenceRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'order_number_sequences';
    }

    /**
     * Atomicky zvýší a vrátí pořadové číslo pro daný rok.
     * Řádek zůstává zamčený do konce transakce – souběžné požadavky se serializují.
     */
    public function nextValue(int $year): int
    {
        $value = $this->db->query(
            'INSERT INTO order_number_sequences (year, last_value) VALUES (?, 1)
             ON CONFLICT (year) DO UPDATE SET last_value = order_number_sequences.last_value + 1
             RETURNING last_value',
            $year,
        )->fetchField();

        return (int) $value;
    }
}

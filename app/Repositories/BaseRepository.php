<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\EntityNotFoundException;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

/**
 * Společný základ repozitářů nad Nette Database Explorer.
 * Všechny dotazy jsou parametrizované – hodnoty se nikdy nevkládají do SQL řetězce.
 */
abstract class BaseRepository
{
    public function __construct(
        protected readonly Explorer $db,
    ) {
    }

    /** @return literal-string */
    abstract protected function getTableName(): string;

    /** @return Selection<ActiveRow> */
    public function table(): Selection
    {
        return $this->db->table($this->getTableName());
    }

    public function findById(int $id): ?ActiveRow
    {
        return $this->table()->get($id);
    }

    public function getById(int $id): ActiveRow
    {
        return $this->findById($id) ?? throw EntityNotFoundException::create($this->getTableName(), $id);
    }

    /**
     * Načte řádek se zámkem FOR UPDATE (pouze uvnitř transakce).
     */
    public function getForUpdate(int $id): ActiveRow
    {
        if (!$this->db->getConnection()->getPdo()->inTransaction()) {
            throw new \LogicException('Row lock requires an active transaction.');
        }

        // Zámek řádku; další dotaz v rámci transakce již čte aktuální potvrzená data.
        $locked = $this->db->query(
            'SELECT id FROM ' . $this->getTableName() . ' WHERE id = ? FOR UPDATE',
            $id,
        )->fetchField();

        if ($locked === null || $locked === false) {
            throw EntityNotFoundException::create($this->getTableName(), $id);
        }

        return $this->getById($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): ActiveRow
    {
        $row = $this->table()->insert($data);
        if (!$row instanceof ActiveRow) {
            throw new \LogicException('Insert did not return a row.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): int
    {
        return $this->table()->where('id', $id)->update($data);
    }

    public function delete(int $id): int
    {
        return $this->table()->where('id', $id)->delete();
    }

    /**
     * Escapuje zástupné znaky LIKE, aby uživatelský vstup nefungoval jako vzor.
     */
    protected static function likePattern(string $term): string
    {
        return '%' . addcslashes(trim($term), '%_\\') . '%';
    }
}

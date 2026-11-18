<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Model\Enum\UserRole;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class UserRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'users';
    }

    public function findByEmail(string $email): ?ActiveRow
    {
        return $this->table()->where('lower(email) = ?', mb_strtolower(trim($email)))->fetch();
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $selection = $this->table()->where('lower(email) = ?', mb_strtolower(trim($email)));
        if ($exceptId !== null) {
            $selection->where('id != ?', $exceptId);
        }

        return $selection->count('*') > 0;
    }

    /** @return Selection<ActiveRow> */
    public function findAllOrdered(): Selection
    {
        return $this->table()->order('is_active DESC, last_name, first_name');
    }

    /**
     * Aktivní technici pro přiřazení k zakázce.
     *
     * @return array<int, string>
     */
    public function getTechnicianOptions(): array
    {
        $options = [];
        $rows = $this->table()
            ->where('role', UserRole::Technician->value)
            ->where('is_active', true)
            ->order('last_name, first_name');

        foreach ($rows as $row) {
            $options[(int) $row->id] = $row->first_name . ' ' . $row->last_name;
        }

        return $options;
    }

    public function isActiveTechnician(int $id): bool
    {
        return $this->table()
            ->where('id', $id)
            ->where('role', UserRole::Technician->value)
            ->where('is_active', true)
            ->count('*') > 0;
    }

    public function countActiveAdmins(?int $exceptId = null): int
    {
        $selection = $this->table()->where('role', UserRole::Admin->value)->where('is_active', true);
        if ($exceptId !== null) {
            $selection->where('id != ?', $exceptId);
        }

        return $selection->count('*');
    }

    public function registerFailedLogin(int $id, int $maxAttempts, int $lockoutMinutes): void
    {
        $this->db->query(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE
                    WHEN failed_login_attempts + 1 >= ? THEN now() + make_interval(mins => ?)
                    ELSE locked_until
                END
             WHERE id = ?',
            $maxAttempts,
            $lockoutMinutes,
            $id,
        );
    }

    public function registerSuccessfulLogin(int $id): void
    {
        $this->update($id, [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => new \DateTimeImmutable(),
        ]);
    }
}

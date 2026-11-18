<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Input\UserData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\ValidationException;
use App\Model\Enum\UserRole;
use App\Repositories\UserRepository;
use App\Security\Actor;
use App\Security\PasswordPolicy;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Nette\Utils\Validators;

final class UserService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly UserRepository $users,
        private readonly Passwords $passwords,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Vytvoření uživatele. Pokud $actor je null, jde o systémovou operaci (konzolový příkaz).
     */
    public function create(UserData $data, ?Actor $actor): int
    {
        if ($actor !== null) {
            $this->assertAdmin($actor);
        }
        $this->validate($data, null, requirePassword: true);

        return $this->db->transaction(function () use ($data, $actor): int {
            $row = $this->users->insert([
                'email' => $data->email,
                'password_hash' => $this->passwords->hash((string) $data->password),
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'phone' => $data->phone,
                'role' => $data->role->value,
                'is_active' => $data->isActive,
            ]);
            $id = (int) $row->id;
            $this->auditLogger->log('user.created', 'user', $id, [
                'email' => $data->email,
                'role' => $data->role->value,
            ], $actor);

            return $id;
        });
    }

    public function update(int $id, UserData $data, Actor $actor): void
    {
        $this->assertAdmin($actor);
        $this->validate($data, $id, requirePassword: false);

        $this->db->transaction(function () use ($id, $data, $actor): void {
            $current = $this->users->getForUpdate($id);

            if ($id === $actor->id && (!$data->isActive || $data->role !== UserRole::Admin)) {
                throw new DomainException('Nemůžete deaktivovat svůj účet ani si odebrat roli administrátora.');
            }
            $wasActiveAdmin = (string) $current->role === UserRole::Admin->value && (bool) $current->is_active;
            $staysActiveAdmin = $data->role === UserRole::Admin && $data->isActive;
            if ($wasActiveAdmin && !$staysActiveAdmin && $this->users->countActiveAdmins($id) === 0) {
                throw new DomainException('V systému musí zůstat alespoň jeden aktivní administrátor.');
            }

            $values = [
                'email' => $data->email,
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'phone' => $data->phone,
                'role' => $data->role->value,
                'is_active' => $data->isActive,
            ];
            $diff = AuditLogger::diff($current->toArray(), $values);

            if ($data->password !== null) {
                $values['password_hash'] = $this->passwords->hash($data->password);
                $values['failed_login_attempts'] = 0;
                $values['locked_until'] = null;
                $diff['password'] = ['old' => null, 'new' => '***'];
            }

            if ($diff === []) {
                return;
            }
            $this->users->update($id, $values);
            $this->auditLogger->log('user.updated', 'user', $id, $diff, $actor);
        });
    }

    public function changeOwnPassword(Actor $actor, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): void
    {
        $user = $this->users->getById($actor->id);
        if (!$this->passwords->verify($currentPassword, (string) $user->password_hash)) {
            throw ValidationException::forField('current_password', 'Současné heslo není správné.');
        }
        $errors = PasswordPolicy::validate($newPassword);
        if ($errors !== []) {
            throw ValidationException::forField('new_password', implode(' ', $errors));
        }

        $this->db->transaction(function () use ($actor, $newPassword): void {
            $this->users->update($actor->id, ['password_hash' => $this->passwords->hash($newPassword)]);
            $this->auditLogger->log('user.password_changed', 'user', $actor->id, [], $actor);
        });
    }

    private function validate(UserData $data, ?int $exceptId, bool $requirePassword): void
    {
        $errors = [];
        if (!Validators::isEmail($data->email)) {
            $errors['email'] = 'Zadejte platný e-mail.';
        } elseif ($this->users->emailExists($data->email, $exceptId)) {
            $errors['email'] = 'Uživatel s tímto e-mailem již existuje.';
        }
        if ($data->firstName === '') {
            $errors['first_name'] = 'Vyplňte jméno.';
        }
        if ($data->lastName === '') {
            $errors['last_name'] = 'Vyplňte příjmení.';
        }
        if ($requirePassword && $data->password === null) {
            $errors['password'] = 'Zadejte heslo.';
        }
        if ($data->password !== null) {
            $passwordErrors = PasswordPolicy::validate($data->password);
            if ($passwordErrors !== []) {
                $errors['password'] = implode(' ', $passwordErrors);
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Formulář obsahuje chyby.', $errors);
        }
    }

    private function assertAdmin(Actor $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException('Uživatele může spravovat pouze administrátor.');
        }
    }
}

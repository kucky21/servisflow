<?php

declare(strict_types=1);

namespace App\Security;

use App\Model\Enum\UserRole;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use Nette\Security\AuthenticationException;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;

/**
 * Přihlášení e-mailem a heslem.
 *
 *  - hesla jsou hashována (Nette Passwords / password_hash), při změně algoritmu se přehashují,
 *  - po opakovaných neúspěších se účet dočasně zablokuje,
 *  - chybové hlášení neprozrazuje, zda účet existuje,
 *  - do session se ukládá jen ID; role a stav účtu se při každém požadavku čtou z databáze
 *    (deaktivace nebo změna role se projeví okamžitě).
 */
final class Authenticator implements \Nette\Security\Authenticator, IdentityHandler
{
    private const string GENERIC_ERROR = 'Nesprávný e-mail nebo heslo.';

    /** Platný bcrypt hash náhodného řetězce – slouží jen k vyrovnání doby odezvy. */
    private const string TIMING_DUMMY_HASH = '$2y$12$hsYIuq3fhr4mFEIsw2y.Fef/GLNwSqj2udKJlanaRZjxVqzeOqpXq';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Passwords $passwords,
        private readonly AuditLogger $auditLogger,
        private readonly int $maxFailedLogins = 5,
        private readonly int $lockoutMinutes = 15,
    ) {
    }

    public function authenticate(string $user, #[\SensitiveParameter] string $password): IIdentity
    {
        $row = $this->users->findByEmail($user);

        if ($row === null) {
            // Vyrovnání časování – útočník nepozná existenci účtu podle doby odezvy
            $this->passwords->verify($password, self::TIMING_DUMMY_HASH);
            throw new AuthenticationException(self::GENERIC_ERROR, self::IdentityNotFound);
        }

        $id = (int) $row->id;
        $lockedUntil = $row->locked_until;
        if ($lockedUntil instanceof \DateTimeInterface && $lockedUntil > new \DateTimeImmutable()) {
            $minutes = (int) ceil(($lockedUntil->getTimestamp() - time()) / 60);
            throw new AuthenticationException(sprintf(
                'Účet je kvůli opakovaným neúspěšným pokusům dočasně zablokován. Zkuste to znovu za %d min.',
                max(1, $minutes),
            ), self::NotApproved);
        }

        if (!$this->passwords->verify($password, (string) $row->password_hash)) {
            $this->users->registerFailedLogin($id, $this->maxFailedLogins, $this->lockoutMinutes);
            $this->auditLogger->log('user.login_failed', 'user', $id, ['email' => (string) $row->email]);
            throw new AuthenticationException(self::GENERIC_ERROR, self::InvalidCredential);
        }

        if (!(bool) $row->is_active) {
            throw new AuthenticationException('Účet je deaktivován. Kontaktujte administrátora.', self::NotApproved);
        }

        if ($this->passwords->needsRehash((string) $row->password_hash)) {
            $this->users->update($id, ['password_hash' => $this->passwords->hash($password)]);
        }

        $this->users->registerSuccessfulLogin($id);
        $this->auditLogger->log('user.login', 'user', $id, [], new Actor($id, UserRole::from((string) $row->role), ''));

        return $this->createIdentity($row);
    }

    public function sleepIdentity(IIdentity $identity): IIdentity
    {
        return new SimpleIdentity($identity->getId());
    }

    public function wakeupIdentity(IIdentity $identity): ?IIdentity
    {
        $row = $this->users->findById((int) $identity->getId());
        if ($row === null || !(bool) $row->is_active) {
            return null;
        }

        return $this->createIdentity($row);
    }

    private function createIdentity(\Nette\Database\Table\ActiveRow $row): SimpleIdentity
    {
        return new SimpleIdentity((int) $row->id, [(string) $row->role], [
            'email' => (string) $row->email,
            'firstName' => (string) $row->first_name,
            'lastName' => (string) $row->last_name,
            'role' => (string) $row->role,
        ]);
    }
}

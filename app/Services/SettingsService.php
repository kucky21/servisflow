<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\CompanyProfile;
use App\Exceptions\ValidationException;
use App\Model\Money\Money;
use App\Repositories\SettingsRepository;
use App\Security\Actor;
use Brick\Math\BigDecimal;
use Nette\Database\Explorer;

/**
 * Konfigurovatelné hodnoty aplikace (firma, hodinová sazba, sazba DPH…).
 */
final class SettingsService
{
    /** Klíče, které lze měnit přes administraci. */
    public const array EDITABLE_KEYS = [
        'company_name', 'company_street', 'company_city', 'company_zip', 'company_ico', 'company_dic',
        'company_phone', 'company_email', 'company_web', 'company_bank_account',
        'default_hourly_rate', 'vat_rate', 'default_due_days', 'document_footer',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly AuditLogger $auditLogger,
        private readonly Explorer $db,
    ) {
    }

    public function get(string $key, string $default = ''): string
    {
        $this->cache ??= $this->repository->getAll();

        return $this->cache[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $this->cache ??= $this->repository->getAll();

        return $this->cache;
    }

    public function getDefaultHourlyRate(): Money
    {
        return Money::of($this->get('default_hourly_rate', '0'));
    }

    public function getVatRate(): BigDecimal
    {
        return BigDecimal::of($this->get('vat_rate', '21'));
    }

    public function getDefaultDueDays(): int
    {
        return max(0, (int) $this->get('default_due_days', '5'));
    }

    public function getCompanyProfile(): CompanyProfile
    {
        return new CompanyProfile(
            name: $this->get('company_name', 'ServisHub'),
            street: $this->get('company_street'),
            city: $this->get('company_city'),
            zip: $this->get('company_zip'),
            ico: $this->get('company_ico'),
            dic: $this->get('company_dic'),
            phone: $this->get('company_phone'),
            email: $this->get('company_email'),
            web: $this->get('company_web'),
            bankAccount: $this->get('company_bank_account'),
            documentFooter: $this->get('document_footer'),
        );
    }

    /**
     * @param array<string, string> $values
     */
    public function update(array $values, Actor $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new \App\Exceptions\AccessDeniedException('Nastavení může měnit pouze administrátor.');
        }

        $values = array_intersect_key($values, array_flip(self::EDITABLE_KEYS));
        $this->validate($values);

        $this->db->transaction(function () use ($values, $actor): void {
            $diff = AuditLogger::diff($this->getAll(), $values);
            foreach ($values as $key => $value) {
                $this->repository->set($key, trim($value));
            }
            if ($diff !== []) {
                $this->auditLogger->log('settings.updated', 'settings', null, $diff, $actor);
            }
        });

        $this->cache = null;
    }

    /**
     * @param array<string, string> $values
     */
    private function validate(array $values): void
    {
        if (isset($values['vat_rate'])) {
            $vat = BigDecimal::of(str_replace(',', '.', $values['vat_rate']));
            if ($vat->isNegative() || $vat->isGreaterThan(100)) {
                throw ValidationException::forField('vat_rate', 'Sazba DPH musí být mezi 0 a 100 %.');
            }
        }
        if (isset($values['default_hourly_rate']) && Money::of($values['default_hourly_rate'])->isNegative()) {
            throw ValidationException::forField('default_hourly_rate', 'Hodinová sazba nesmí být záporná.');
        }
    }
}

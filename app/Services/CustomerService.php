<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Input\CustomerData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\ValidationException;
use App\Model\Enum\CustomerType;
use App\Model\Validation\CzechValidators;
use App\Repositories\CustomerRepository;
use App\Security\Actor;
use Nette\Database\Explorer;
use Nette\Utils\Validators;

final class CustomerService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly CustomerRepository $customers,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(CustomerData $data, Actor $actor): int
    {
        $this->assertCanManage($actor);
        $this->validate($data, null);

        return $this->db->transaction(function () use ($data, $actor): int {
            $row = $this->customers->insert($data->toRow());
            $id = (int) $row->id;
            $this->auditLogger->log('customer.created', 'customer', $id, ['name' => $data->name], $actor);

            return $id;
        });
    }

    public function update(int $id, CustomerData $data, Actor $actor): void
    {
        $this->assertCanManage($actor);
        $this->validate($data, $id);

        $this->db->transaction(function () use ($id, $data, $actor): void {
            $current = $this->customers->getForUpdate($id);
            $newRow = $data->toRow();
            $diff = AuditLogger::diff($current->toArray(), $newRow);
            if ($diff === []) {
                return;
            }
            $this->customers->update($id, $newRow);
            $this->auditLogger->log('customer.updated', 'customer', $id, $diff, $actor);
        });
    }

    /**
     * Zákazníka se zakázkami nelze smazat (zachování historie). Jeho zařízení se smažou kaskádou.
     */
    public function delete(int $id, Actor $actor): void
    {
        $this->assertCanManage($actor);

        $this->db->transaction(function () use ($id, $actor): void {
            $customer = $this->customers->getForUpdate($id);
            if ($this->customers->hasOrders($id)) {
                throw new DomainException('Zákazníka nelze smazat, protože má servisní zakázky. Historie zakázek musí zůstat zachována.');
            }
            $this->customers->delete($id);
            $this->auditLogger->log('customer.deleted', 'customer', $id, ['name' => (string) $customer->name], $actor);
        });
    }

    private function validate(CustomerData $data, ?int $exceptId): void
    {
        $errors = [];

        if ($data->name === '' || mb_strlen($data->name) > 200) {
            $errors['name'] = 'Vyplňte jméno nebo název (max. 200 znaků).';
        }
        if ($data->email === null && $data->phone === null) {
            $errors['email'] = 'Zadejte alespoň e-mail nebo telefon.';
        }
        if ($data->email !== null && !Validators::isEmail($data->email)) {
            $errors['email'] = 'Zadejte platný e-mail.';
        }
        if ($data->phone !== null && !CzechValidators::isValidPhone($data->phone)) {
            $errors['phone'] = 'Zadejte platné telefonní číslo.';
        }
        if ($data->ico !== null) {
            if (!CzechValidators::isValidIco($data->ico)) {
                $errors['ico'] = 'IČO není platné (nesouhlasí kontrolní číslice).';
            } elseif ($this->customers->icoExists($data->ico, $exceptId)) {
                $errors['ico'] = 'Zákazník s tímto IČO již existuje.';
            }
        }
        if ($data->dic !== null && !CzechValidators::isValidDic($data->dic)) {
            $errors['dic'] = 'DIČ musí mít tvar např. CZ12345678.';
        }
        if ($data->type === CustomerType::Company && $data->ico === null) {
            $errors['ico'] = 'U firmy vyplňte IČO.';
        }

        if ($errors !== []) {
            throw new ValidationException('Formulář obsahuje chyby.', $errors);
        }
    }

    private function assertCanManage(Actor $actor): void
    {
        if (!$actor->isManagerial()) {
            throw new AccessDeniedException('Zákazníky může spravovat pouze vedoucí servisu.');
        }
    }
}

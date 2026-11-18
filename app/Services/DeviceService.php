<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Input\DeviceData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\ValidationException;
use App\Repositories\CustomerRepository;
use App\Repositories\DeviceRepository;
use App\Security\Actor;
use Nette\Database\Explorer;

final class DeviceService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly DeviceRepository $devices,
        private readonly CustomerRepository $customers,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(DeviceData $data, Actor $actor): int
    {
        $this->assertCanManage($actor);
        $this->validate($data, null);

        return $this->db->transaction(function () use ($data, $actor): int {
            $this->customers->getById($data->customerId);
            $row = $this->devices->insert($data->toRow());
            $id = (int) $row->id;
            $this->auditLogger->log('device.created', 'device', $id, [
                'customer_id' => $data->customerId,
                'device' => $data->manufacturer . ' ' . $data->model,
            ], $actor);

            return $id;
        });
    }

    public function update(int $id, DeviceData $data, Actor $actor): void
    {
        $this->assertCanManage($actor);
        $this->validate($data, $id);

        $this->db->transaction(function () use ($id, $data, $actor): void {
            $current = $this->devices->getForUpdate($id);
            if ((int) $current->customer_id !== $data->customerId && $this->devices->hasOrders($id)) {
                throw ValidationException::forField('customer_id', 'Zařízení se zakázkami nelze převést na jiného zákazníka.');
            }
            $row = $data->toRow();
            $diff = AuditLogger::diff($current->toArray(), $row);
            if ($diff === []) {
                return;
            }
            $this->devices->update($id, $row);
            $this->auditLogger->log('device.updated', 'device', $id, $diff, $actor);
        });
    }

    public function delete(int $id, Actor $actor): void
    {
        $this->assertCanManage($actor);

        $this->db->transaction(function () use ($id, $actor): void {
            $device = $this->devices->getForUpdate($id);
            if ($this->devices->hasOrders($id)) {
                throw new DomainException('Zařízení nelze smazat, protože k němu existují servisní zakázky.');
            }
            $this->devices->delete($id);
            $this->auditLogger->log('device.deleted', 'device', $id, [
                'device' => $device->manufacturer . ' ' . $device->model,
            ], $actor);
        });
    }

    private function validate(DeviceData $data, ?int $exceptId): void
    {
        $errors = [];
        if ($data->customerId <= 0 || $this->customers->findById($data->customerId) === null) {
            $errors['customer_id'] = 'Vyberte existujícího zákazníka.';
        }
        if ($data->manufacturer === '') {
            $errors['manufacturer'] = 'Vyplňte výrobce.';
        }
        if ($data->model === '') {
            $errors['model'] = 'Vyplňte model.';
        }
        if ($data->serialNumber !== null && $this->devices->serialExists($data->manufacturer, $data->serialNumber, $exceptId)) {
            $errors['serial_number'] = 'Zařízení tohoto výrobce s tímto sériovým číslem je již evidováno.';
        }

        if ($errors !== []) {
            throw new ValidationException('Formulář obsahuje chyby.', $errors);
        }
    }

    private function assertCanManage(Actor $actor): void
    {
        if (!$actor->isManagerial()) {
            throw new AccessDeniedException('Zařízení může spravovat pouze vedoucí servisu.');
        }
    }
}

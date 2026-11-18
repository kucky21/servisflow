<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\Filter\DeviceFilter;
use App\Model\Enum\DeviceType;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class DeviceRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'devices';
    }

    /** @return Selection<ActiveRow> */
    public function search(DeviceFilter $filter): Selection
    {
        $selection = $this->table()->order('devices.registered_at DESC');

        if ($filter->query !== null && trim($filter->query) !== '') {
            $pattern = self::likePattern($filter->query);
            $selection->where(
                'devices.manufacturer ILIKE ? OR devices.model ILIKE ? OR devices.serial_number ILIKE ? OR customer.name ILIKE ?',
                $pattern,
                $pattern,
                $pattern,
                $pattern,
            );
        }

        if ($filter->type !== null) {
            $selection->where('devices.type', $filter->type->value);
        }

        return $selection;
    }

    /** @return Selection<ActiveRow> */
    public function findByCustomer(int $customerId): Selection
    {
        return $this->table()->where('customer_id', $customerId)->order('registered_at DESC');
    }

    /**
     * @return array<int, string>
     */
    public function getOptionsForCustomer(int $customerId): array
    {
        $options = [];
        foreach ($this->findByCustomer($customerId) as $row) {
            $options[(int) $row->id] = self::formatLabel(
                (string) $row->type,
                (string) $row->manufacturer,
                (string) $row->model,
                $row->serial_number === null ? null : (string) $row->serial_number,
            );
        }

        return $options;
    }

    public static function formatLabel(string $type, string $manufacturer, string $model, ?string $serial): string
    {
        $label = DeviceType::from($type)->label() . ': ' . $manufacturer . ' ' . $model;

        return $serial !== null && $serial !== '' ? $label . ' (SN ' . $serial . ')' : $label;
    }

    public function belongsToCustomer(int $deviceId, int $customerId): bool
    {
        return $this->table()->where('id', $deviceId)->where('customer_id', $customerId)->count('*') > 0;
    }

    public function serialExists(string $manufacturer, string $serial, ?int $exceptId = null): bool
    {
        $selection = $this->table()
            ->where('lower(manufacturer) = ?', mb_strtolower(trim($manufacturer)))
            ->where('lower(serial_number) = ?', mb_strtolower(trim($serial)));
        if ($exceptId !== null) {
            $selection->where('id != ?', $exceptId);
        }

        return $selection->count('*') > 0;
    }

    public function hasOrders(int $id): bool
    {
        return $this->db->table('service_orders')->where('device_id', $id)->count('*') > 0;
    }
}

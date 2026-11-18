<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\Filter\OrderFilter;
use App\Model\Enum\OrderStatus;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

final class ServiceOrderRepository extends BaseRepository
{
    /** @return literal-string */
    protected function getTableName(): string
    {
        return 'service_orders';
    }

    /** @return Selection<ActiveRow> */
    public function search(OrderFilter $filter): Selection
    {
        $selection = $this->table();

        if ($filter->query !== null && trim($filter->query) !== '') {
            $pattern = self::likePattern($filter->query);
            $selection->where(
                'service_orders.order_number ILIKE ? OR service_orders.problem_description ILIKE ?'
                . ' OR customer.name ILIKE ? OR device.serial_number ILIKE ? OR device.model ILIKE ?',
                $pattern,
                $pattern,
                $pattern,
                $pattern,
                $pattern,
            );
        }

        if ($filter->status !== null) {
            $selection->where('service_orders.status', $filter->status->value);
        } elseif ($filter->scope === OrderFilter::SCOPE_ACTIVE) {
            $selection->where('service_orders.status', OrderStatus::activeValues());
        } elseif ($filter->scope === OrderFilter::SCOPE_DONE) {
            $selection->where('service_orders.status', [
                OrderStatus::Completed->value,
                OrderStatus::HandedOver->value,
                OrderStatus::Cancelled->value,
            ]);
        }

        if ($filter->technicianId !== null) {
            $selection->where('service_orders.assigned_technician_id', $filter->technicianId);
        }
        if ($filter->unassignedOnly) {
            $selection->where('service_orders.assigned_technician_id IS NULL');
        }
        if ($filter->priority !== null) {
            $selection->where('service_orders.priority', $filter->priority->value);
        }
        if ($filter->overdueOnly) {
            $selection
                ->where('service_orders.due_date < CURRENT_DATE')
                ->where('service_orders.status', OrderStatus::activeValues());
        }

        // Urgentní nahoře, pak nejnovější
        $selection
            ->order("CASE service_orders.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->order('service_orders.received_at DESC');

        return $selection;
    }

    /** @return Selection<ActiveRow> */
    public function findByCustomer(int $customerId): Selection
    {
        return $this->table()->where('customer_id', $customerId)->order('received_at DESC');
    }

    /** @return Selection<ActiveRow> */
    public function findByDevice(int $deviceId): Selection
    {
        return $this->table()->where('device_id', $deviceId)->order('received_at DESC');
    }

    public function findByNumber(string $number): ?ActiveRow
    {
        return $this->table()->where('order_number', $number)->fetch();
    }
}

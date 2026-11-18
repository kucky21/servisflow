<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\DashboardData;
use App\Model\Money\Money;
use App\Repositories\CustomerRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\InventoryItemRepository;
use App\Repositories\ServiceOrderRepository;
use App\Security\Actor;

/**
 * Data pro úvodní přehled. Technik vidí statistiky pouze ze svých zakázek.
 */
final class DashboardService
{
    private const int CHART_MONTHS = 6;

    private const array MONTH_NAMES = [
        1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly CustomerRepository $customers,
        private readonly InventoryItemRepository $inventory,
        private readonly ServiceOrderRepository $orders,
    ) {
    }

    public function getData(Actor $actor): DashboardData
    {
        $technicianId = $actor->isTechnician() ? $actor->id : null;
        $counters = $this->dashboard->getOrderCounters($technicianId);

        $completed = $this->dashboard->getMonthlyCompleted(self::CHART_MONTHS, $technicianId);
        $received = $this->dashboard->getMonthlyReceived(self::CHART_MONTHS, $technicianId);
        $monthly = [];
        foreach ($completed as $row) {
            $monthly[] = [
                'month' => $row['month'],
                'label' => self::monthLabel($row['month']),
                'count' => $row['count'],
                'received' => $received[$row['month']] ?? 0,
                'value' => $row['value'],
            ];
        }

        $recentOrders = $this->orders->table()->order('received_at DESC')->limit(8);
        if ($technicianId !== null) {
            $recentOrders->where('assigned_technician_id', $technicianId);
        }

        return new DashboardData(
            activeOrders: $counters['active'],
            completedThisMonth: $counters['completedThisMonth'],
            overdueOrders: $counters['overdue'],
            awaitingParts: $counters['awaitingParts'],
            unassignedOrders: $counters['unassigned'],
            dueToday: $this->dashboard->countDueToday($technicianId),
            completedValueThisMonth: Money::of($counters['completedValueThisMonth']),
            newCustomers: $this->customers->countCreatedSince(new \DateTimeImmutable('-30 days')),
            lowStockCount: $this->inventory->countLowStock(),
            averageTurnaroundDays: $this->dashboard->getAverageTurnaroundDays($technicianId),
            statusCounts: $this->dashboard->getStatusCounts($technicianId),
            monthly: $monthly,
            recentActivity: $this->dashboard->getRecentActivity(12, $technicianId),
            recentOrders: array_values($recentOrders->fetchAll()),
            lowStockItems: array_values($this->inventory->findLowStock(8)->fetchAll()),
        );
    }

    private static function monthLabel(string $yearMonth): string
    {
        [$year, $month] = array_map(intval(...), explode('-', $yearMonth));

        return self::MONTH_NAMES[$month] . ' ' . $year;
    }
}

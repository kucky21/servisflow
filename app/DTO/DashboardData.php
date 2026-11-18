<?php

declare(strict_types=1);

namespace App\DTO;

use App\Model\Money\Money;
use Nette\Database\Row;
use Nette\Database\Table\ActiveRow;

final readonly class DashboardData
{
    /**
     * @param array<string, int> $statusCounts
     * @param list<array{month: string, label: string, count: int, received: int, value: string}> $monthly
     * @param list<Row> $recentActivity
     * @param list<ActiveRow> $recentOrders
     * @param list<ActiveRow> $lowStockItems
     */
    public function __construct(
        public int $activeOrders,
        public int $completedThisMonth,
        public int $overdueOrders,
        public int $awaitingParts,
        public int $unassignedOrders,
        public int $dueToday,
        public Money $completedValueThisMonth,
        public int $newCustomers,
        public int $lowStockCount,
        public ?float $averageTurnaroundDays,
        public array $statusCounts,
        public array $monthly,
        public array $recentActivity,
        public array $recentOrders,
        public array $lowStockItems,
    ) {
    }
}

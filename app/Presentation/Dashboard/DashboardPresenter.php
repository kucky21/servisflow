<?php

declare(strict_types=1);

namespace App\Presentation\Dashboard;

use App\Model\Enum\OrderStatus;
use App\Presentation\BasePresenter;
use App\Security\RequirePermission;
use App\Services\DashboardService;

#[RequirePermission('dashboard', 'view')]
final class DashboardPresenter extends BasePresenter
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $data = $this->dashboardService->getData($this->getActor());
        $this->template->data = $data;

        $statusChart = ['labels' => [], 'values' => [], 'colors' => []];
        foreach (OrderStatus::cases() as $status) {
            $count = $data->statusCounts[$status->value] ?? 0;
            if ($count === 0) {
                continue;
            }
            $statusChart['labels'][] = $status->label();
            $statusChart['values'][] = $count;
            $statusChart['colors'][] = $status->color();
        }

        $this->template->chartData = [
            'status' => $statusChart,
            'monthly' => [
                'labels' => array_column($data->monthly, 'label'),
                'completed' => array_column($data->monthly, 'count'),
                'received' => array_column($data->monthly, 'received'),
                'value' => array_map(static fn (array $m): float => (float) $m['value'], $data->monthly),
            ],
        ];
    }
}

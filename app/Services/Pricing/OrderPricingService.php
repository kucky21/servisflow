<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTO\Pricing\LaborLine;
use App\DTO\Pricing\MaterialLine;
use App\DTO\Pricing\PriceBreakdown;
use App\Model\Money\Money;
use App\Repositories\OrderMaterialRepository;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\WorkLogRepository;
use Nette\Database\Table\ActiveRow;

/**
 * Sestaví cenový rozpis zakázky z uložených výkazů práce a materiálu.
 * Používá výhradně historické ceny uložené u řádků (sazba ve výkazu, cena materiálu při výdeji),
 * takže pozdější změna ceníku neovlivní již evidované zakázky.
 */
final class OrderPricingService
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly ServiceOrderRepository $orders,
        private readonly WorkLogRepository $workLogs,
        private readonly OrderMaterialRepository $materials,
    ) {
    }

    public function getBreakdown(int $orderId): PriceBreakdown
    {
        return $this->getBreakdownForOrder($this->orders->getById($orderId));
    }

    public function getBreakdownForOrder(ActiveRow $order): PriceBreakdown
    {
        $orderId = (int) $order->id;

        $laborLines = [];
        foreach ($this->workLogs->findByOrder($orderId) as $log) {
            $laborLines[] = new LaborLine(
                description: (string) $log->description,
                minutes: (int) $log->minutes,
                hourlyRate: Money::of((string) $log->hourly_rate),
            );
        }

        $materialLines = [];
        foreach ($this->materials->findByOrder($orderId) as $material) {
            $materialLines[] = new MaterialLine(
                name: (string) $material->name,
                quantity: (int) $material->quantity,
                unitPrice: Money::of((string) $material->unit_price),
                sku: (string) $material->sku,
                unit: (string) $material->unit,
            );
        }

        return $this->calculator->calculate(
            $laborLines,
            $materialLines,
            (string) $order->discount_percent,
            (string) $order->vat_rate,
        );
    }
}

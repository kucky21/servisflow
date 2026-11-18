<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Exceptions\DomainException;
use App\Model\Enum\DocumentType;
use App\Model\Enum\OrderStatus;
use App\Repositories\OrderMaterialRepository;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\WorkLogRepository;
use App\Security\Actor;
use App\Security\OrderAccessPolicy;
use App\Services\AuditLogger;
use App\Services\Pricing\OrderPricingService;
use App\Services\SettingsService;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Nette\Bridges\ApplicationLatte\LatteFactory;

/**
 * Generování PDF dokumentů zakázky (příjmový protokol, servisní protokol, cenový rozpis).
 * HTML se skládá z Latte šablon a převádí knihovnou mPDF s fontem DejaVu Sans (plná podpora češtiny).
 */
final class DocumentService
{
    public function __construct(
        private readonly string $tempDir,
        private readonly LatteFactory $latteFactory,
        private readonly ServiceOrderRepository $orders,
        private readonly WorkLogRepository $workLogs,
        private readonly OrderMaterialRepository $materials,
        private readonly StatusHistoryRepository $history,
        private readonly OrderPricingService $pricing,
        private readonly SettingsService $settings,
        private readonly OrderAccessPolicy $accessPolicy,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function generate(DocumentType $type, int $orderId, Actor $actor): GeneratedDocument
    {
        $order = $this->orders->getById($orderId);
        $this->accessPolicy->assertCanView($actor, $order);

        $status = OrderStatus::from((string) $order->status);
        if (!$type->isAvailableFor($status)) {
            throw new DomainException(sprintf('%s lze vystavit až po dokončení zakázky.', $type->label()));
        }

        $html = $this->renderHtml($type, $orderId);
        $content = $this->toPdf($html, $type->label() . ' ' . $order->order_number);

        $this->auditLogger->log('document.generated', 'service_order', $orderId, ['type' => $type->value], $actor);

        return new GeneratedDocument(
            fileName: sprintf('%s-%s.pdf', $type->filePrefix(), $order->order_number),
            content: $content,
        );
    }

    public function renderHtml(DocumentType $type, int $orderId): string
    {
        $order = $this->orders->getById($orderId);
        $latte = $this->latteFactory->create();

        return $latte->renderToString(__DIR__ . '/templates/' . $type->value . '.latte', [
            'type' => $type,
            'company' => $this->settings->getCompanyProfile(),
            'order' => $order,
            'customer' => $order->ref('customers', 'customer_id'),
            'device' => $order->ref('devices', 'device_id'),
            'technician' => $order->ref('users', 'assigned_technician_id'),
            'workLogs' => $this->workLogs->findByOrder($orderId)->fetchAll(),
            'materials' => $this->materials->findByOrder($orderId)->fetchAll(),
            'history' => array_reverse($this->history->findByOrder($orderId)->fetchAll()),
            'breakdown' => $this->pricing->getBreakdownForOrder($order),
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }

    private function toPdf(string $html, string $title): string
    {
        $tempDir = $this->tempDir;
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new \RuntimeException("Cannot create mPDF temp dir {$tempDir}.");
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusans',
            'default_font_size' => 9,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 18,
            'margin_footer' => 8,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($this->settings->getCompanyProfile()->name);
        $mpdf->SetCreator('ServisHub');
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-size:7.5pt;color:#64748b;border-top:0.3mm solid #cbd5e1;padding-top:2mm">'
            . '<tr><td>' . htmlspecialchars($title, ENT_QUOTES) . '</td>'
            . '<td align="right">Strana {PAGENO} z {nbpg}</td></tr></table>',
        );
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}

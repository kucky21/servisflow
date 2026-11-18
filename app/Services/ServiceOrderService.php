<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Input\OrderData;
use App\DTO\Input\WorkLogData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\OrderLockedException;
use App\Exceptions\ValidationException;
use App\Model\Enum\OrderStatus;
use App\Model\Money\Money;
use App\Repositories\CommentRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkLogRepository;
use App\Security\Actor;
use App\Security\OrderAccessPolicy;
use Brick\Math\BigDecimal;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

/**
 * Správa servisních zakázek (mimo změny stavů – ty řeší {@see OrderWorkflowService}).
 */
final class ServiceOrderService
{
    public function __construct(
        private readonly Explorer $db,
        private readonly ServiceOrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly DeviceRepository $devices,
        private readonly UserRepository $users,
        private readonly StatusHistoryRepository $statusHistory,
        private readonly CommentRepository $comments,
        private readonly WorkLogRepository $workLogs,
        private readonly OrderNumberGenerator $numberGenerator,
        private readonly SettingsService $settings,
        private readonly OrderAccessPolicy $accessPolicy,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Přijetí nové zakázky. Vrací ID zakázky.
     */
    public function create(OrderData $data, Actor $actor): int
    {
        $this->accessPolicy->assertCanManage($actor);
        $this->validate($data, isNew: true);

        return $this->db->transaction(function () use ($data, $actor): int {
            $now = new \DateTimeImmutable();
            $number = $this->numberGenerator->next($now);

            $row = $this->orders->insert([
                'order_number' => $number,
                'customer_id' => $data->customerId,
                'device_id' => $data->deviceId,
                'assigned_technician_id' => $data->technicianId,
                'created_by_id' => $actor->id,
                'status' => OrderStatus::Received->value,
                'priority' => $data->priority->value,
                'problem_description' => $data->problemDescription,
                'accessories' => $data->accessories,
                'diagnosis' => $data->diagnosis,
                'estimated_price' => Money::ofNullable($data->estimatedPrice)?->toDecimalString(),
                'hourly_rate' => (Money::ofNullable($data->hourlyRate) ?? $this->settings->getDefaultHourlyRate())->toDecimalString(),
                'discount_percent' => (string) BigDecimal::of($data->discountPercent ?? '0')->toScale(2),
                'vat_rate' => (string) $this->settings->getVatRate()->toScale(2),
                'received_at' => $now,
                'due_date' => $data->dueDate ?? $now->modify('+' . $this->settings->getDefaultDueDays() . ' weekdays')->setTime(0, 0),
                'internal_note' => $data->internalNote,
            ]);
            $orderId = (int) $row->id;

            $this->statusHistory->insert([
                'service_order_id' => $orderId,
                'from_status' => null,
                'to_status' => OrderStatus::Received->value,
                'changed_by_id' => $actor->id,
                'note' => 'Zakázka přijata do servisu',
            ]);
            $this->auditLogger->log('order.created', 'service_order', $orderId, [
                'order_number' => $number,
                'customer_id' => $data->customerId,
                'device_id' => $data->deviceId,
            ], $actor);

            return $orderId;
        });
    }

    /**
     * Úprava údajů zakázky vedoucím. Cenové parametry nelze měnit u dokončených zakázek.
     */
    public function update(int $orderId, OrderData $data, Actor $actor): void
    {
        $this->accessPolicy->assertCanManage($actor);
        $this->validate($data, isNew: false);

        $this->db->transaction(function () use ($orderId, $data, $actor): void {
            $order = $this->orders->getForUpdate($orderId);
            $status = OrderStatus::from((string) $order->status);
            if ($status->isFinal()) {
                throw OrderLockedException::forStatus($status, 'Úprava zakázky');
            }

            $values = [
                'priority' => $data->priority->value,
                'problem_description' => $data->problemDescription,
                'accessories' => $data->accessories,
                'diagnosis' => $data->diagnosis,
                'estimated_price' => Money::ofNullable($data->estimatedPrice)?->toDecimalString(),
                'due_date' => $data->dueDate,
                'internal_note' => $data->internalNote,
            ];

            if ($status->allowsWork()) {
                if ($data->hourlyRate !== null) {
                    $values['hourly_rate'] = Money::of($data->hourlyRate)->toDecimalString();
                }
                if ($data->discountPercent !== null) {
                    $values['discount_percent'] = (string) BigDecimal::of($data->discountPercent)->toScale(2);
                }
            }

            $diff = AuditLogger::diff($order->toArray(), $values);
            if ($diff !== []) {
                $this->orders->update($orderId, $values);
                $this->auditLogger->log('order.updated', 'service_order', $orderId, $diff, $actor);
            }

            $currentTechnician = $order->assigned_technician_id === null ? null : (int) $order->assigned_technician_id;
            if ($data->technicianId !== $currentTechnician) {
                $this->assignTechnician($orderId, $data->technicianId, $actor);
            }
        });
    }

    public function assignTechnician(int $orderId, ?int $technicianId, Actor $actor): void
    {
        $this->accessPolicy->assertCanManage($actor);
        if ($technicianId !== null && !$this->users->isActiveTechnician($technicianId)) {
            throw ValidationException::forField('assigned_technician_id', 'Vybraný uživatel není aktivní technik.');
        }

        $this->db->transaction(function () use ($orderId, $technicianId, $actor): void {
            $order = $this->orders->getForUpdate($orderId);
            $status = OrderStatus::from((string) $order->status);
            if ($status->isFinal()) {
                throw OrderLockedException::forStatus($status, 'Přiřazení technika');
            }

            $previous = $order->assigned_technician_id === null ? null : (int) $order->assigned_technician_id;
            if ($previous === $technicianId) {
                return;
            }

            $this->orders->update($orderId, ['assigned_technician_id' => $technicianId]);
            $this->auditLogger->log('order.technician_assigned', 'service_order', $orderId, [
                'assigned_technician_id' => ['old' => $previous, 'new' => $technicianId],
            ], $actor);
        });
    }

    /**
     * Zápis diagnostiky a odhadu ceny (technik přiřazený k zakázce nebo vedoucí).
     */
    public function updateDiagnosis(int $orderId, ?string $diagnosis, ?string $estimatedPrice, Actor $actor): void
    {
        $price = Money::ofNullable($estimatedPrice);
        if ($price !== null && $price->isNegative()) {
            throw ValidationException::forField('estimated_price', 'Odhadovaná cena nesmí být záporná.');
        }

        $this->db->transaction(function () use ($orderId, $diagnosis, $price, $actor): void {
            $order = $this->lockForWork($orderId, $actor, 'Úprava diagnostiky');
            $values = [
                'diagnosis' => $diagnosis === null || trim($diagnosis) === '' ? null : trim($diagnosis),
                'estimated_price' => $price?->toDecimalString(),
            ];
            $diff = AuditLogger::diff($order->toArray(), $values);
            if ($diff === []) {
                return;
            }
            $this->orders->update($orderId, $values);
            $this->auditLogger->log('order.diagnosis_updated', 'service_order', $orderId, $diff, $actor);
        });
    }

    public function addComment(int $orderId, string $body, Actor $actor): int
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw ValidationException::forField('body', 'Komentář musí mít 1–5000 znaků.');
        }

        $order = $this->orders->getById($orderId);
        $this->accessPolicy->assertCanView($actor, $order);

        $row = $this->comments->insert([
            'service_order_id' => $orderId,
            'user_id' => $actor->id,
            'body' => $body,
        ]);

        return (int) $row->id;
    }

    /**
     * Evidence práce. Sazba se ukládá do výkazu (historická hodnota).
     */
    public function addWorkLog(int $orderId, WorkLogData $data, Actor $actor): int
    {
        if ($data->description === '' || mb_strlen($data->description) > 500) {
            throw ValidationException::forField('description', 'Popis práce musí mít 1–500 znaků.');
        }
        if ($data->minutes <= 0 || $data->minutes > 24 * 60) {
            throw ValidationException::forField('hours', 'Doba práce musí být mezi 0,25 a 24 hodinami.');
        }
        if ($data->workDate > new \DateTimeImmutable('today')) {
            throw ValidationException::forField('work_date', 'Práci nelze vykázat do budoucna.');
        }

        return $this->db->transaction(function () use ($orderId, $data, $actor): int {
            $order = $this->lockForWork($orderId, $actor, 'Evidence práce');

            $row = $this->workLogs->insert([
                'service_order_id' => $orderId,
                'user_id' => $actor->id,
                'description' => $data->description,
                'work_date' => $data->workDate,
                'minutes' => $data->minutes,
                'hourly_rate' => (string) $order->hourly_rate,
            ]);
            $this->auditLogger->log('order.work_logged', 'service_order', $orderId, [
                'minutes' => $data->minutes,
                'description' => $data->description,
            ], $actor);

            return (int) $row->id;
        });
    }

    public function deleteWorkLog(int $workLogId, Actor $actor): int
    {
        return $this->db->transaction(function () use ($workLogId, $actor): int {
            $orderId = (int) $this->workLogs->getById($workLogId)->service_order_id;
            $this->lockForWork($orderId, $actor, 'Mazání výkazu');

            $log = $this->workLogs->findById($workLogId)
                ?? throw new DomainException('Výkaz již byl smazán.');
            if (!$actor->isManagerial() && (int) $log->user_id !== $actor->id) {
                throw new AccessDeniedException('Smazat můžete pouze své vlastní výkazy.');
            }

            $this->workLogs->delete($workLogId);
            $this->auditLogger->log('order.work_deleted', 'service_order', $orderId, [
                'minutes' => (int) $log->minutes,
                'description' => (string) $log->description,
            ], $actor);

            return $orderId;
        });
    }

    private function lockForWork(int $orderId, Actor $actor, string $operation): ActiveRow
    {
        $order = $this->orders->getForUpdate($orderId);
        $this->accessPolicy->assertCanView($actor, $order);

        $status = OrderStatus::from((string) $order->status);
        if (!$status->allowsWork()) {
            throw OrderLockedException::forStatus($status, $operation);
        }

        return $order;
    }

    private function validate(OrderData $data, bool $isNew): void
    {
        $errors = [];

        if ($isNew) {
            if ($this->customers->findById($data->customerId) === null) {
                $errors['customer_id'] = 'Vyberte zákazníka.';
            } elseif (!$this->devices->belongsToCustomer($data->deviceId, $data->customerId)) {
                $errors['device_id'] = 'Vybrané zařízení nepatří zvolenému zákazníkovi.';
            }
            if ($data->dueDate !== null && $data->dueDate < new \DateTimeImmutable('today')) {
                $errors['due_date'] = 'Termín dokončení nemůže být v minulosti.';
            }
        }

        $length = mb_strlen($data->problemDescription);
        if ($length < 5 || $length > 5000) {
            $errors['problem_description'] = 'Popište závadu (5–5000 znaků).';
        }
        if ($data->technicianId !== null && !$this->users->isActiveTechnician($data->technicianId)) {
            $errors['assigned_technician_id'] = 'Vybraný uživatel není aktivní technik.';
        }
        if ($data->estimatedPrice !== null && Money::of($data->estimatedPrice)->isNegative()) {
            $errors['estimated_price'] = 'Odhadovaná cena nesmí být záporná.';
        }
        if ($data->hourlyRate !== null && Money::of($data->hourlyRate)->isNegative()) {
            $errors['hourly_rate'] = 'Hodinová sazba nesmí být záporná.';
        }
        if ($data->discountPercent !== null) {
            $discount = BigDecimal::of($data->discountPercent);
            if ($discount->isNegative() || $discount->isGreaterThan(100)) {
                $errors['discount_percent'] = 'Sleva musí být v rozsahu 0–100 %.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Formulář obsahuje chyby.', $errors);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\Input\OrderData;
use App\DTO\Input\WorkLogData;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\ValidationException;
use App\Model\Enum\OrderPriority;
use App\Security\Authenticator;
use App\Services\OrderNumberGenerator;
use App\Services\ServiceOrderService;
use Nette\Database\UniqueConstraintViolationException;
use Nette\Security\AuthenticationException;

final class ServiceOrderServiceTest extends IntegrationTestCase
{
    private ServiceOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->service(ServiceOrderService::class);
    }

    public function testOrderNumbersAreSequentialAndUnique(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = (string) $this->orderRow($this->createOrder())->order_number;
        }

        $year = date('Y');
        self::assertSame(
            ["SRV-$year-0001", "SRV-$year-0002", "SRV-$year-0003", "SRV-$year-0004", "SRV-$year-0005"],
            $numbers,
        );
    }

    public function testOrderNumberSequenceIsPerYear(): void
    {
        $generator = $this->service(OrderNumberGenerator::class);
        $this->db->transaction(function () use ($generator): void {
            self::assertSame('SRV-2030-0001', $generator->next(new \DateTimeImmutable('2030-05-01')));
            self::assertSame('SRV-2030-0002', $generator->next(new \DateTimeImmutable('2030-12-31')));
            self::assertSame('SRV-2031-0001', $generator->next(new \DateTimeImmutable('2031-01-01')));
        });
    }

    public function testDatabaseRejectsDuplicateOrderNumber(): void
    {
        $orderId = $this->createOrder();
        $order = $this->orderRow($orderId);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->table('service_orders')->insert([
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'device_id' => $order->device_id,
            'problem_description' => 'Duplicita',
            'hourly_rate' => '100',
            'vat_rate' => '21',
        ]);
    }

    public function testDeviceMustBelongToCustomer(): void
    {
        $customerA = $this->createCustomer('Zákazník A');
        $customerB = $this->createCustomer('Zákazník B');
        $deviceOfB = $this->createDevice($customerB);

        try {
            $this->orders->create($this->orderData($customerA, $deviceOfB), $this->manager);
            self::fail('Očekávána chyba validace.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('device_id', $e->getFieldErrors());
        }
    }

    public function testValidatesOrderInput(): void
    {
        $customerId = $this->createCustomer();
        $deviceId = $this->createDevice($customerId);

        try {
            $this->orders->create($this->orderData($customerId, $deviceId, description: 'abc', estimate: '-5', discount: '120', technicianId: $this->manager->id), $this->manager);
            self::fail('Očekávána chyba validace.');
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            self::assertArrayHasKey('problem_description', $errors);
            self::assertArrayHasKey('estimated_price', $errors);
            self::assertArrayHasKey('discount_percent', $errors);
            self::assertArrayHasKey('assigned_technician_id', $errors, 'Přiřadit lze jen aktivního technika.');
        }
        self::assertSame(0, $this->db->table('service_orders')->count('*'));
    }

    public function testCreateUsesConfiguredRatesAndRecordsHistory(): void
    {
        $customerId = $this->createCustomer();
        $deviceId = $this->createDevice($customerId);

        $orderId = $this->orders->create($this->orderData($customerId, $deviceId), $this->manager);

        $order = $this->orderRow($orderId);
        self::assertSame('received', $order->status);
        self::assertSame('890.00', (string) $order->hourly_rate);
        self::assertSame('21.00', (string) $order->vat_rate);
        self::assertNotNull($order->due_date);
        self::assertSame(1, $this->db->table('service_order_status_history')->where('service_order_id', $orderId)->count('*'));
        self::assertSame(1, $this->db->table('audit_logs')->where('action', 'order.created')->count('*'));
    }

    public function testTechnicianCannotCreateOrders(): void
    {
        $customerId = $this->createCustomer();
        $deviceId = $this->createDevice($customerId);

        $this->expectException(AccessDeniedException::class);
        $this->orders->create($this->orderData($customerId, $deviceId), $this->technician);
    }

    public function testWorkLogStoresHourlyRateSnapshot(): void
    {
        $orderId = $this->createOrder();
        $this->logWork($orderId, 45);
        $this->db->table('service_orders')->where('id', $orderId)->update(['hourly_rate' => '2000.00']);

        $log = $this->db->table('work_logs')->where('service_order_id', $orderId)->fetch();
        self::assertSame('1000.00', (string) $log?->hourly_rate);
    }

    public function testCannotLogWorkInFuture(): void
    {
        $orderId = $this->createOrder();

        $this->expectException(ValidationException::class);
        $this->orders->addWorkLog($orderId, new WorkLogData('Práce', 30, new \DateTimeImmutable('+2 days')), $this->technician);
    }

    public function testTechnicianCanDeleteOnlyOwnWorkLog(): void
    {
        $orderId = $this->createOrder();
        $this->logWork($orderId, 30, $this->manager);
        $logId = (int) $this->db->table('work_logs')->fetch()?->id;

        $this->expectException(AccessDeniedException::class);
        $this->orders->deleteWorkLog($logId, $this->technician);
    }

    public function testLoginIsLockedAfterRepeatedFailures(): void
    {
        $authenticator = $this->service(Authenticator::class);
        for ($i = 0; $i < 5; $i++) {
            try {
                $authenticator->authenticate('technik@test.local', 'spatne-heslo');
            } catch (AuthenticationException) {
            }
        }

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('dočasně zablokován');
        $authenticator->authenticate('technik@test.local', 'Heslo123!');
    }

    public function testSuccessfulLoginReturnsRoleFromDatabase(): void
    {
        $identity = $this->service(Authenticator::class)->authenticate('VEDOUCI@test.local', 'Heslo123!');

        self::assertSame(['manager'], $identity->getRoles());
    }

    private function orderData(
        int $customerId,
        int $deviceId,
        string $description = 'Prasklý displej po pádu.',
        ?string $estimate = null,
        ?string $discount = null,
        ?int $technicianId = null,
    ): OrderData {
        return new OrderData(
            customerId: $customerId,
            deviceId: $deviceId,
            technicianId: $technicianId,
            priority: OrderPriority::High,
            problemDescription: $description,
            accessories: null,
            diagnosis: null,
            estimatedPrice: $estimate,
            dueDate: null,
            internalNote: null,
            hourlyRate: null,
            discountPercent: $discount,
        );
    }
}

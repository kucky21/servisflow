<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\Input\CustomerData;
use App\DTO\Input\DeviceData;
use App\DTO\Input\InventoryItemData;
use App\DTO\Input\OrderData;
use App\DTO\Input\WorkLogData;
use App\Model\Enum\CustomerType;
use App\Model\Enum\DeviceType;
use App\Model\Enum\OrderPriority;
use App\Model\Enum\UserRole;
use App\Security\Actor;
use App\Services\CustomerService;
use App\Services\DeviceService;
use App\Services\InventoryService;
use App\Services\ServiceOrderService;
use Nette\Database\Explorer;
use Nette\DI\Container;
use Nette\Security\Passwords;
use PHPUnit\Framework\TestCase;

/**
 * Základ integračních testů nad skutečnou PostgreSQL databází.
 * Před každým testem se data vyprázdní (schéma zůstává z migrací).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Container $container;
    protected Explorer $db;
    protected Actor $admin;
    protected Actor $manager;
    protected Actor $technician;
    protected Actor $otherTechnician;

    protected function setUp(): void
    {
        $this->container = TestDatabase::container();
        $this->db = $this->container->getByType(Explorer::class);

        $this->db->query(
            'TRUNCATE audit_logs, service_order_materials, stock_movements, work_logs, service_order_comments,
                service_order_status_history, service_orders, order_number_sequences, inventory_items,
                devices, customers, users RESTART IDENTITY CASCADE',
        );

        $this->admin = $this->createUser(UserRole::Admin, 'admin@test.local');
        $this->manager = $this->createUser(UserRole::Manager, 'vedouci@test.local');
        $this->technician = $this->createUser(UserRole::Technician, 'technik@test.local');
        $this->otherTechnician = $this->createUser(UserRole::Technician, 'technik2@test.local');
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    protected function service(string $type): object
    {
        return $this->container->getByType($type);
    }

    protected function createUser(UserRole $role, string $email, string $password = 'Heslo123!'): Actor
    {
        $row = $this->db->table('users')->insert([
            'email' => $email,
            'password_hash' => $this->service(Passwords::class)->hash($password),
            'first_name' => 'Test',
            'last_name' => ucfirst($role->value),
            'role' => $role->value,
        ]);
        assert($row instanceof \Nette\Database\Table\ActiveRow);

        return new Actor((int) $row->id, $role, 'Test ' . $role->value);
    }

    protected function createCustomer(string $name = 'Jan Novák'): int
    {
        return $this->service(CustomerService::class)->create(new CustomerData(
            CustomerType::Person,
            $name,
            null,
            \Nette\Utils\Strings::webalize($name, '.') . '@example.com',
            '+420 777 000 111',
            'Hlavní 1',
            'Brno',
            '602 00',
            null,
            null,
            null,
        ), $this->manager);
    }

    protected function createDevice(int $customerId, ?string $serial = null): int
    {
        return $this->service(DeviceService::class)->create(new DeviceData(
            $customerId,
            DeviceType::Laptop,
            'Lenovo',
            'ThinkPad T14',
            $serial ?? 'SN' . random_int(100000, 999999),
            null,
        ), $this->manager);
    }

    protected function createOrder(?int $technicianId = null, string $discount = '0'): int
    {
        $customerId = $this->createCustomer('Zákazník ' . random_int(1000, 9999));
        $deviceId = $this->createDevice($customerId);

        return $this->service(ServiceOrderService::class)->create(new OrderData(
            customerId: $customerId,
            deviceId: $deviceId,
            technicianId: $technicianId ?? $this->technician->id,
            priority: OrderPriority::Normal,
            problemDescription: 'Notebook se nezapne.',
            accessories: null,
            diagnosis: null,
            estimatedPrice: null,
            dueDate: null,
            internalNote: null,
            hourlyRate: '1000.00',
            discountPercent: $discount,
        ), $this->manager);
    }

    protected function createItem(string $sku = 'SSD-TEST', int $quantity = 5, string $sale = '1500.00'): int
    {
        return $this->service(InventoryService::class)->createItem(new InventoryItemData(
            sku: $sku,
            name: 'Testovací položka ' . $sku,
            description: null,
            unit: 'ks',
            purchasePrice: '1000.00',
            salePrice: $sale,
            minQuantity: 1,
            isActive: true,
            initialQuantity: $quantity,
        ), $this->manager);
    }

    protected function logWork(int $orderId, int $minutes = 60, ?Actor $actor = null): void
    {
        $this->service(ServiceOrderService::class)->addWorkLog(
            $orderId,
            new WorkLogData('Oprava', $minutes, new \DateTimeImmutable('today')),
            $actor ?? $this->technician,
        );
    }

    protected function orderRow(int $id): \Nette\Database\Table\ActiveRow
    {
        $row = $this->db->table('service_orders')->get($id);
        self::assertNotNull($row);

        return $row;
    }

    protected function itemQuantity(int $itemId): int
    {
        return (int) $this->db->table('inventory_items')->get($itemId)?->quantity;
    }
}

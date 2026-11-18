<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Model\Enum\OrderStatus;
use App\Model\Enum\UserRole;
use App\Security\Actor;
use App\Security\AuthorizatorFactory;
use App\Security\OrderAccessPolicy;
use Nette\Security\Permission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    private Permission $acl;

    protected function setUp(): void
    {
        $this->acl = AuthorizatorFactory::create();
    }

    /**
     * @return iterable<string, array{UserRole, string, string, bool}>
     */
    public static function permissions(): iterable
    {
        // Technik
        yield 'technik vidí zakázky' => [UserRole::Technician, 'order', 'view', true];
        yield 'technik vykazuje práci' => [UserRole::Technician, 'order', 'logWork', true];
        yield 'technik mění stav' => [UserRole::Technician, 'order', 'changeStatus', true];
        yield 'technik nezakládá zakázky' => [UserRole::Technician, 'order', 'create', false];
        yield 'technik nepřiřazuje' => [UserRole::Technician, 'order', 'assign', false];
        yield 'technik nevidí zákazníky' => [UserRole::Technician, 'customer', 'view', false];
        yield 'technik vidí sklad' => [UserRole::Technician, 'inventory', 'view', true];
        yield 'technik nenaskladňuje' => [UserRole::Technician, 'inventory', 'receive', false];
        yield 'technik nespravuje uživatele' => [UserRole::Technician, 'user', 'view', false];
        // Vedoucí
        yield 'vedoucí spravuje zákazníky' => [UserRole::Manager, 'customer', 'delete', true];
        yield 'vedoucí zakládá zakázky' => [UserRole::Manager, 'order', 'create', true];
        yield 'vedoucí naskladňuje' => [UserRole::Manager, 'inventory', 'receive', true];
        yield 'vedoucí dědí práva technika' => [UserRole::Manager, 'order', 'logWork', true];
        yield 'vedoucí nespravuje uživatele' => [UserRole::Manager, 'user', 'edit', false];
        yield 'vedoucí nemění nastavení' => [UserRole::Manager, 'settings', 'edit', false];
        yield 'vedoucí nevidí audit' => [UserRole::Manager, 'audit', 'view', false];
        // Administrátor
        yield 'admin spravuje uživatele' => [UserRole::Admin, 'user', 'edit', true];
        yield 'admin mění nastavení' => [UserRole::Admin, 'settings', 'edit', true];
        yield 'admin vidí audit' => [UserRole::Admin, 'audit', 'view', true];
        yield 'admin dědí práva vedoucího' => [UserRole::Admin, 'customer', 'edit', true];
    }

    #[DataProvider('permissions')]
    public function testRolePermissions(UserRole $role, string $resource, string $privilege, bool $expected): void
    {
        self::assertSame($expected, $this->acl->isAllowed($role->value, $resource, $privilege));
    }

    public function testTechnicianSeesOnlyAssignedOrders(): void
    {
        $policy = new OrderAccessPolicy();
        $technician = new Actor(5, UserRole::Technician, 'Technik');

        self::assertTrue($policy->canView($technician, 5));
        self::assertFalse($policy->canView($technician, 6));
        self::assertFalse($policy->canView($technician, null));
    }

    public function testManagerSeesAllOrders(): void
    {
        $policy = new OrderAccessPolicy();

        self::assertTrue($policy->canView(new Actor(1, UserRole::Manager, 'V'), 99));
        self::assertTrue($policy->canView(new Actor(1, UserRole::Admin, 'A'), null));
    }

    public function testWorkIsNotAllowedOnClosedOrders(): void
    {
        $policy = new OrderAccessPolicy();
        $technician = new Actor(5, UserRole::Technician, 'Technik');

        self::assertTrue($policy->canWork($technician, 5, OrderStatus::InRepair));
        self::assertFalse($policy->canWork($technician, 5, OrderStatus::Completed));
        self::assertFalse($policy->canWork($technician, 5, OrderStatus::Cancelled));
        self::assertFalse($policy->canWork($technician, 6, OrderStatus::InRepair));
        self::assertFalse($policy->canManage($technician));
    }
}

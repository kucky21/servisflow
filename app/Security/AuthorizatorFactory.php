<?php

declare(strict_types=1);

namespace App\Security;

use App\Model\Enum\UserRole;
use Nette\Security\Permission;

/**
 * Role-based access control (ACL) na úrovni modulů a akcí.
 *
 * Dědičnost rolí: technician ⊂ manager ⊂ admin.
 * Oprávnění na úrovni konkrétních záznamů (technik vidí jen své zakázky)
 * řeší {@see OrderAccessPolicy}.
 */
final class AuthorizatorFactory
{
    public const string RES_DASHBOARD = 'dashboard';
    public const string RES_CUSTOMER = 'customer';
    public const string RES_DEVICE = 'device';
    public const string RES_ORDER = 'order';
    public const string RES_INVENTORY = 'inventory';
    public const string RES_DOCUMENT = 'document';
    public const string RES_USER = 'user';
    public const string RES_SETTINGS = 'settings';
    public const string RES_AUDIT = 'audit';

    private const array RESOURCES = [
        self::RES_DASHBOARD, self::RES_CUSTOMER, self::RES_DEVICE, self::RES_ORDER, self::RES_INVENTORY,
        self::RES_DOCUMENT, self::RES_USER, self::RES_SETTINGS, self::RES_AUDIT,
    ];

    public static function create(): Permission
    {
        $acl = new Permission();

        $acl->addRole(UserRole::Technician->value);
        $acl->addRole(UserRole::Manager->value, UserRole::Technician->value);
        $acl->addRole(UserRole::Admin->value, UserRole::Manager->value);

        foreach (self::RESOURCES as $resource) {
            $acl->addResource($resource);
        }

        // Technik: přiřazené zakázky, výkazy práce, materiál, komentáře, povolené stavy
        $acl->allow(UserRole::Technician->value, self::RES_DASHBOARD, 'view');
        $acl->allow(UserRole::Technician->value, self::RES_ORDER, [
            'view', 'changeStatus', 'logWork', 'useMaterial', 'comment', 'diagnose',
        ]);
        $acl->allow(UserRole::Technician->value, self::RES_INVENTORY, 'view');
        $acl->allow(UserRole::Technician->value, self::RES_DOCUMENT, 'view');

        // Vedoucí servisu: zákazníci, zařízení, zakázky, sklad, cenové podklady
        $acl->allow(UserRole::Manager->value, self::RES_CUSTOMER, Permission::All);
        $acl->allow(UserRole::Manager->value, self::RES_DEVICE, Permission::All);
        $acl->allow(UserRole::Manager->value, self::RES_ORDER, Permission::All);
        $acl->allow(UserRole::Manager->value, self::RES_INVENTORY, Permission::All);
        $acl->allow(UserRole::Manager->value, self::RES_DOCUMENT, Permission::All);

        // Administrátor: navíc uživatelé, nastavení a audit
        $acl->allow(UserRole::Admin->value, self::RES_USER, Permission::All);
        $acl->allow(UserRole::Admin->value, self::RES_SETTINGS, Permission::All);
        $acl->allow(UserRole::Admin->value, self::RES_AUDIT, Permission::All);

        return $acl;
    }
}

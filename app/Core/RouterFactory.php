<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\RouteList;

final class RouterFactory
{
    public static function createRouter(): RouteList
    {
        $router = new RouteList();

        $router->addRoute('prihlaseni', 'Sign:in');
        $router->addRoute('profil', 'Profile:default');
        $router->addRoute('zakaznici[/<action>][/<id \d+>]', 'Customer:default');
        $router->addRoute('zarizeni[/<action>][/<id \d+>]', 'Device:default');
        $router->addRoute('zakazky[/<action>][/<id \d+>]', 'Order:default');
        $router->addRoute('sklad[/<action>][/<id \d+>]', 'Inventory:default');
        $router->addRoute('dokumenty/<action>/<id \d+>', 'Document:default');
        $router->addRoute('uzivatele[/<action>][/<id \d+>]', 'User:default');
        $router->addRoute('nastaveni', 'Settings:default');
        $router->addRoute('audit', 'Audit:default');
        $router->addRoute('', 'Dashboard:default');

        return $router;
    }
}

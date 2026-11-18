<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Bootstrap;
use Contributte\Console\Application;
use Nette\DI\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Sdílený DI kontejner nad testovací databází.
 */
final class TestDatabase
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        return self::$container ??= (new Bootstrap())->bootTestContainer();
    }

    /**
     * Smaže a znovu vytvoří schéma testovací databáze pomocí migrací.
     */
    public static function reset(): void
    {
        $console = self::container()->getByType(Application::class);
        $console->setAutoExit(false);
        $output = new BufferedOutput();
        ob_start(); // tiskárna migrací píše přímo na výstup
        try {
            $code = $console->run(new ArrayInput(['command' => 'migrations:reset']), $output);
        } finally {
            $printed = (string) ob_get_clean();
        }
        if ($code !== 0) {
            throw new \RuntimeException("Migrace testovací databáze selhaly:\n" . $printed . $output->fetch());
        }
    }
}

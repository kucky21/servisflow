<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

/**
 * Sestavení DI kontejneru pro web, konzoli a testy.
 *
 * Konfigurace se řídí proměnnými prostředí:
 *  - APP_ENV   (dev | production | test)
 *  - APP_DEBUG (1 = Tracy debug režim, nikdy v produkci)
 */
final class Bootstrap
{
    private readonly string $rootDir;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
    }

    public static function getEnvironment(): string
    {
        $env = getenv('APP_ENV');

        return is_string($env) && $env !== '' ? $env : 'production';
    }

    public static function isProduction(): bool
    {
        return self::getEnvironment() === 'production';
    }

    public function bootWebApplication(): Container
    {
        return $this->createConfigurator(consoleMode: false)->createContainer();
    }

    public function bootConsoleApplication(): Container
    {
        return $this->createConfigurator(consoleMode: true)->createContainer();
    }

    /**
     * Kontejner pro integrační testy – používá oddělenou testovací databázi.
     */
    public function bootTestContainer(): Container
    {
        $testDb = getenv('DB_TEST_NAME');
        $env = $this->getEnvParameters();
        $env['DB_NAME'] = is_string($testDb) && $testDb !== '' ? $testDb : ($env['DB_NAME'] ?? 'servishub') . '_test';
        $env['APP_ENV'] = 'test';

        $configurator = $this->createConfigurator(consoleMode: true, tempSuffix: '/test', env: $env);
        $configurator->setDebugMode(false);

        return $configurator->createContainer();
    }

    /**
     * @param array<string, string>|null $env
     */
    private function createConfigurator(bool $consoleMode, string $tempSuffix = '', ?array $env = null): Configurator
    {
        $configurator = new Configurator();
        $appEnv = self::getEnvironment();

        $debug = getenv('APP_DEBUG') === '1' && $appEnv !== 'production';
        $configurator->setDebugMode($debug);
        $configurator->enableTracy($this->rootDir . '/log');
        $configurator->setTimeZone('Europe/Prague');
        $configurator->setTempDirectory($this->rootDir . '/temp' . $tempSuffix);

        $configurator->addStaticParameters([
            'rootDir' => $this->rootDir,
            'consoleMode' => $consoleMode,
        ]);
        $configurator->addDynamicParameters([
            'env' => $env ?? $this->getEnvParameters(),
        ]);

        $configDir = $this->rootDir . '/config';
        $configurator->addConfig($configDir . '/common.neon');
        if (is_file($configDir . '/local.neon')) {
            $configurator->addConfig($configDir . '/local.neon');
        }

        return $configurator;
    }

    /**
     * @return array<string, string>
     */
    private function getEnvParameters(): array
    {
        $defaults = [
            'APP_ENV' => 'production',
            'APP_URL' => 'http://localhost',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '5432',
            'DB_NAME' => 'servishub',
            'DB_USER' => 'servishub',
            'DB_PASSWORD' => '',
            'DEMO_PASSWORD' => '',
        ];

        return array_merge($defaults, getenv());
    }
}

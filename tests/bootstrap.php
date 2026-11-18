<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Prague');

// Integrační testy používají samostatnou databázi (DB_TEST_NAME, výchozí <DB_NAME>_test).
// Schéma se před během testů vytvoří znovu z migrací – stejná cesta jako v produkci.
if (getenv('SKIP_DB_RESET') !== '1' && getenv('DB_HOST') !== false) {
    App\Tests\Integration\TestDatabase::reset();
}

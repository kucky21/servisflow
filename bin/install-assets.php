<?php

/**
 * Zkopíruje frontendové assety distribuované přes Composer do veřejného adresáře.
 * Projekt záměrně nepoužívá Node.js – vše potřebné pochází z Composer balíčků.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root . '/www/assets/vendor';

if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
    fwrite(STDERR, "Nelze vytvořit adresář {$target}\n");
    exit(1);
}

$assets = [
    'vendor/nette/forms/src/assets/netteForms.min.js' => 'netteForms.min.js',
];

foreach ($assets as $source => $name) {
    $sourcePath = $root . '/' . $source;
    if (!is_file($sourcePath)) {
        fwrite(STDERR, "Asset {$source} nebyl nalezen, přeskakuji.\n");
        continue;
    }
    copy($sourcePath, $target . '/' . $name);
    echo "Asset {$name} nainstalován.\n";
}

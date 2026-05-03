<?php

declare(strict_types=1);

$autoload = null;
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Install dependencies with composer install, or run from the Phalanx monorepo root.\n");
    exit(1);
}

require_once $autoload;

$demoNamespaces = [
    'Acme\\StoaDemo\\Basic\\' => __DIR__ . '/01-basic-routing/src/',
    'Acme\\StoaDemo\\Contracts\\' => __DIR__ . '/02-contracts-validation/src/',
    'Acme\\StoaDemo\\Advanced\\' => __DIR__ . '/03-advanced-api/src/',
    'Acme\\StoaDemo\\Runtime\\' => __DIR__ . '/04-runtime-lifecycle/src/',
];

spl_autoload_register(static function (string $class) use ($demoNamespaces): void {
    foreach ($demoNamespaces as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

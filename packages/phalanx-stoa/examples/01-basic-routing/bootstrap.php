<?php

declare(strict_types=1);

$autoload = null;
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'] as $candidate) {
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

spl_autoload_register(static function (string $class): void {
    $prefix = 'Acme\\StoaDemo\\Basic\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

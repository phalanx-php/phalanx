<?php

declare(strict_types=1);

$autoload = null;
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
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
require_once __DIR__ . '/support.php';

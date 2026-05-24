<?php

declare(strict_types=1);

$autoload = null;
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    throw new RuntimeException('Cannot find autoload.php');
}

require $autoload;

foreach (['ITIMER_REAL' => 0, 'ITIMER_VIRTUAL' => 1, 'ITIMER_PROF' => 2] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

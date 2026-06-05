<?php

declare(strict_types=1);

foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        require $candidate;

        return;
    }
}

fwrite(STDERR, "Cannot find Composer autoload.php for Mark tests.\n");
exit(1);

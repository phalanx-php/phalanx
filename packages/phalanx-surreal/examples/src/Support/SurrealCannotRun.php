<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Examples\Support;

/**
 * Prints a structured "cannot run" message and exits with code 0.
 * Used when a prerequisite (e.g. the surreal binary) is missing.
 */
final class SurrealCannotRun
{
    public function __invoke(string $title, string $reason, string $fix): never
    {
        printf("%s\n", $title);
        printf("%s\n", str_repeat('=', strlen($title)));
        echo "Status: cannot run\n\n";
        printf("Missing requirement: %s\n\n", $reason);
        printf("Fix: %s\n", $fix);
        exit(0);
    }
}

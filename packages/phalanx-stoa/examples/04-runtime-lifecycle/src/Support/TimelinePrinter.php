<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class TimelinePrinter
{
    /**
     * @param list<array{string, string}> $rows
     */
    public function __invoke(string $title, array $rows): void
    {
        echo "{$title}\n";

        foreach ($rows as [$actor, $message]) {
            printf("  %-10s %s\n", $actor, $message);
        }

        echo "\n";
    }
}

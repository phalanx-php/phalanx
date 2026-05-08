<?php

declare(strict_types=1);

namespace Phalanx\Athena\Examples\Support;

/**
 * Renders a friendly cannot-run message to stdout and returns exit code 0.
 *
 * Demos call this when a prerequisite is absent (no provider key, no
 * Guzzle bridge, etc.) to give the operator a single clear action item.
 *
 * Returns 0, not 1: a missing prerequisite is expected in a local dev
 * environment; it is not a demo failure.
 */
final class DemoFailureRenderer
{
    /**
     * Print a structured cannot-run block and return 0.
     *
     * @param non-empty-string $title   Demo name shown as the heading.
     * @param string           $reason  What is missing.
     * @param string           $fix     One concrete action to resolve it.
     */
    public function cannotRun(string $title, string $reason, string $fix): int
    {
        printf("%s\n", $title);
        printf("%s\n", str_repeat('=', strlen($title)));
        echo "Status: cannot run\n\n";
        printf("Missing requirement: %s\n\n", $reason);
        printf("Fix: %s\n", $fix);

        return 0;
    }

    /**
     * Print a server-start failure with context-sensitive guidance.
     *
     * Used when the OpenSwoole HTTP server fails before accepting requests.
     */
    public function serverFailed(\Throwable $e, string $listenAddress): void
    {
        echo "\nServer failed before accepting requests.\n\n";

        if (str_contains($e->getMessage(), 'Address already in use')) {
            printf("Cause: %s is already in use.\n", $listenAddress);
            echo "Action: stop the other server using that port, then rerun this demo.\n";
            return;
        }

        printf("Cause: %s\n", $e->getMessage());
    }

    /**
     * Extract a human-readable message from a Throwable.
     * Falls back to the class name when the message is empty.
     */
    public static function messageOf(?\Throwable $e): string
    {
        if ($e === null) {
            return 'unknown error';
        }

        $message = $e->getMessage();

        return $message !== '' ? $message : $e::class;
    }
}

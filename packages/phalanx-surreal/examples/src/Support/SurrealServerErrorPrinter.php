<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Examples\Support;

use Phalanx\System\StreamingProcessHandle;

/**
 * Flushes any buffered stderr from the SurrealDB process handle to stdout
 * for demo diagnostics. No-op when there is no error output.
 */
final class SurrealServerErrorPrinter
{
    public function __invoke(StreamingProcessHandle $server): void
    {
        $error = trim($server->getIncrementalErrorOutput());

        if ($error !== '') {
            printf("\nServer error: %s\n", $error);
        }
    }
}

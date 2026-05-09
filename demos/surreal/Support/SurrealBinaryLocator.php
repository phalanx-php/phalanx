<?php

declare(strict_types=1);

namespace Phalanx\Demos\Surreal\Support;

use Phalanx\Boot\AppContext;

/**
 * Resolves the `surreal` binary from the process PATH supplied by Symfony Runtime
 * via AppContext. Returns null when the binary cannot be found.
 */
final class SurrealBinaryLocator
{
    public function __invoke(AppContext $ctx): ?string
    {
        $path = $ctx->string('PATH', '');

        if ($path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'surreal';

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

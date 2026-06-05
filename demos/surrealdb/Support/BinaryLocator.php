<?php

declare(strict_types=1);

namespace Phalanx\Demos\SurrealDb\Support;

use Phalanx\Boot\AppContext;

/**
 * Resolves the `surrealdb` binary from the process PATH supplied by Symfony Runtime
 * via AppContext. Returns null when the binary cannot be found.
 */
final class BinaryLocator
{
    public function __invoke(AppContext $ctx): ?string
    {
        $path = $ctx->string('PATH', '');

        if ($path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'surrealdb';

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

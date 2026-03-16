<?php

declare(strict_types=1);

namespace Convoy\Http;

final class MethodNotAllowedException extends \RuntimeException
{
    /** @param list<string> $allowedMethods */
    public function __construct(
        public private(set) string $method,
        public private(set) string $path,
        public private(set) array $allowedMethods,
    ) {
        parent::__construct("Method {$method} not allowed for {$path}. Allowed: " . implode(', ', $allowedMethods));
    }
}

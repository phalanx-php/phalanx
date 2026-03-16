<?php

declare(strict_types=1);

namespace Convoy\Http;

final class RouteNotFoundException extends \RuntimeException
{
    public function __construct(
        public private(set) string $method,
        public private(set) string $path,
    ) {
        parent::__construct("No route matches {$method} {$path}");
    }
}

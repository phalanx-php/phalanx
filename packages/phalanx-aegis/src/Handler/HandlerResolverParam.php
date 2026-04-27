<?php

declare(strict_types=1);

namespace Phalanx\Handler;

/**
 * Cached reflection metadata for a single handler constructor parameter.
 *
 * Internal to HandlerResolver; never constructed by user code.
 */
final readonly class HandlerResolverParam
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
    ) {}
}

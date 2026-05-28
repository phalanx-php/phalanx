<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

use Closure;

interface Services
{
    /** @param class-string $type */
    public function singleton(string $type): ServiceConfig;

    /** @param class-string $type */
    public function scoped(string $type): ServiceConfig;

    /** @param class-string $type */
    public function eager(string $type): ServiceConfig;

    /**
     * @param class-string $type
     * @param Closure(array<string, mixed>): mixed $fromContext
     */
    public function config(string $type, Closure $fromContext): void;

    /**
     * @param class-string $interface
     * @param class-string $concrete
     */
    public function alias(string $interface, string $concrete): void;
}

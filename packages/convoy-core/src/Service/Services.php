<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;

interface Services
{
    public function singleton(string $type): ServiceConfig;

    public function scoped(string $type): ServiceConfig;

    public function eager(string $type): ServiceConfig;

    public function config(string $type, Closure $fromContext): void;

    public function alias(string $interface, string $concrete): void;
}

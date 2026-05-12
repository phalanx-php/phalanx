<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Runtime\RuntimeContext;
use Phalanx\Trace\Trace;

interface Scope
{
    public RuntimeContext $runtime { get; }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object;

    public function attribute(string $key, mixed $default = null): mixed;

    public function resource(string $key, mixed $default = null): mixed;

    public function withAttribute(string $key, mixed $value): Scope;

    public function trace(): Trace;
}

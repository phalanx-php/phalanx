<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Scope;

interface WriteScope extends Cancellable, Disposable, Suspendable
{
    /**
     * @template T of object
     * @param class-string<T> $resource
     * @return T
     */
    public function use(string $resource): object;

    public function transaction(\Closure $body): mixed;
}

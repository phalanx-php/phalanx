<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Scope;
use Phalanx\Trace\Trace;

final class GenericScopeBagAccessFixture
{
    public function invalid(LegacyBagScope $scope): void
    {
        $scope->attribute('route.params');
        $scope->resource('request');
        $scope->setResource('request', new \stdClass());
        $scope->withAttribute('request.id', 'req-1');
    }

    public function valid(Scope $scope): object
    {
        return $scope->service(\stdClass::class);
    }
}

final class LegacyBagScope implements Scope
{
    public RuntimeContext $runtime {
        get => throw new \RuntimeException();
    }

    public function service(string $type): object
    {
        throw new \RuntimeException();
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function resource(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function setResource(string $key, mixed $value): void
    {
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    public function trace(): Trace
    {
        throw new \RuntimeException();
    }
}

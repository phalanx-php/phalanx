<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionLifecycleScope;

final class GenericScopeBagAccessFixture
{
    public function invalid(ExecutionLifecycleScope $scope): void
    {
        $scope->attribute('route.params');
        $scope->resource('request');
        $scope->setResource('request', new \stdClass());
    }

    public function valid(ExecutionLifecycleScope $scope): object
    {
        return $scope->service(\stdClass::class);
    }
}

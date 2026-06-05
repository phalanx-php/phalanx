<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Http\ResponseSink;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;

final class HttpRequestLifecycleServiceAccessFixture
{
    public function invalid(Scope $scope): void
    {
        $scope->service(\Phalanx\Http\RequestResource::class);
        $scope->service(\Phalanx\Http\RequestDiagnostics::class);
        $scope->service(ResponseSink::class);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Http\ResponseSink;
use Phalanx\Http\HttpRequestDiagnostics;
use Phalanx\Http\HttpRequestResource;

final class HttpRequestLifecycleServiceAccessFixture
{
    public function invalid(Scope $scope): void
    {
        $scope->service(HttpRequestResource::class);
        $scope->service(HttpRequestDiagnostics::class);
        $scope->service(ResponseSink::class);
    }
}

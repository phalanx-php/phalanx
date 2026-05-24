<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\ResponseSink;
use Phalanx\Stoa\StoaRequestDiagnostics;
use Phalanx\Stoa\StoaRequestResource;

final class StoaRequestLifecycleServiceAccessFixture
{
    public function invalid(Scope $scope): void
    {
        $scope->service(StoaRequestResource::class);
        $scope->service(StoaRequestDiagnostics::class);
        $scope->service(ResponseSink::class);
    }

    public function valid(Scope $scope): RequestCtx
    {
        return $scope->service(RequestCtx::class);
    }
}

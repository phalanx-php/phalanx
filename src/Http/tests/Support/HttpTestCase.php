<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Support;

use Phalanx\Http\Http;
use Phalanx\Http\HttpApplicationBuilder;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\PhalanxTestCase;

abstract class HttpTestCase extends PhalanxTestCase
{
    /** @param array<string, mixed> $context */
    protected static function http(array $context = []): HttpApplicationBuilder
    {
        return Http::starting($context)->withLedger(new InProcessLedger());
    }
}

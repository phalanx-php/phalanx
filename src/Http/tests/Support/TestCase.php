<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Support;

use Phalanx\Http\Server;
use Phalanx\Http\ApplicationBuilder;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\PhalanxTestCase;

abstract class TestCase extends PhalanxTestCase
{
    /** @param array<string, mixed> $context */
    protected static function http(array $context = []): \Phalanx\Http\ApplicationBuilder
    {
        return Server::starting($context)->withLedger(new InProcessLedger());
    }
}

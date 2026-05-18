<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectTypesTest extends TestCase
{
    #[Test]
    public function outcomeCarriesRoutingResolutionAndFailure(): void
    {
        $error = new \RuntimeException('tool failed');
        $routed = Outcome::routed(Resolution::LocalTool, data: ['ok' => true]);
        $failed = Outcome::failed(Resolution::McpTool, $error);

        self::assertSame(Resolution::LocalTool, $routed->resolution);
        self::assertSame(['ok' => true], $routed->data);
        self::assertSame(Resolution::McpTool, $failed->resolution);
        self::assertSame($error, $failed->error);
    }

    #[Test]
    public function resolutionCoversCurrentExecutionRoutes(): void
    {
        self::assertSame('built-in', Resolution::BuiltIn->value);
        self::assertSame('local-tool', Resolution::LocalTool->value);
        self::assertSame('mcp-tool', Resolution::McpTool->value);
        self::assertSame('sub-agent', Resolution::SubAgent->value);
    }
}

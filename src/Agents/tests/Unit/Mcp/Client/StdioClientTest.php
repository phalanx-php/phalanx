<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit\Mcp\Client;

use Phalanx\Agents\Mcp\Client\StdioClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StdioClientTest extends TestCase
{
    #[Test]
    public function parseCommandSplitsSimpleCommand(): void
    {
        $result = self::parseCommand('npx server');

        self::assertSame(['npx', 'server'], $result);
    }

    #[Test]
    public function parseCommandHandlesMultipleSpaces(): void
    {
        $result = self::parseCommand('npx  -y  server');

        self::assertSame(['npx', '-y', 'server'], $result);
    }

    #[Test]
    public function parseCommandHandlesTabs(): void
    {
        $result = self::parseCommand("npx\tserver");

        self::assertSame(['npx', 'server'], $result);
    }

    #[Test]
    public function parseCommandTrimsWhitespace(): void
    {
        $result = self::parseCommand('  npx server  ');

        self::assertSame(['npx', 'server'], $result);
    }

    #[Test]
    public function parseCommandThrowsOnEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server endpoint command is empty');

        self::parseCommand('');
    }

    private static function parseCommand(string $command): mixed
    {
        $method = new ReflectionMethod(StdioClient::class, 'parseCommand');

        return $method->invoke(null, $command);
    }
}

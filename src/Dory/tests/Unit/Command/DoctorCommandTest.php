<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Dory\Command\DoctorCommand;
use Phalanx\Dory\DoryConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctorCommandTest extends TestCase
{
    #[Test]
    public function returns_zero_when_environment_is_healthy(): void
    {
        [$scope] = $this->buildScope();
        $command = new DoctorCommand();
        $result = $command($scope);

        self::assertSame(0, $result);
    }

    #[Test]
    public function output_contains_pass_for_php_version(): void
    {
        [$scope, $stream] = $this->buildScope();
        $command = new DoctorCommand();
        $command($scope);

        rewind($stream);
        $output = stream_get_contents($stream);

        self::assertStringContainsString('[pass]', $output);
        self::assertStringContainsString('PHP >= 8.4', $output);
    }

    #[Test]
    public function output_contains_openswoole_check(): void
    {
        [$scope, $stream] = $this->buildScope();
        $command = new DoctorCommand();
        $command($scope);

        rewind($stream);
        $output = stream_get_contents($stream);

        self::assertStringContainsString('OpenSwoole extension', $output);
    }

    #[Test]
    public function output_contains_config_validation(): void
    {
        [$scope, $stream] = $this->buildScope();
        $command = new DoctorCommand();
        $command($scope);

        rewind($stream);
        $output = stream_get_contents($stream);

        self::assertStringContainsString('Dory config', $output);
    }

    #[Test]
    public function reports_invalid_config(): void
    {
        $config = new DoryConfig(scriptTimeout: 0.0);
        [$scope, $stream] = $this->buildScope($config);
        $command = new DoctorCommand();
        $command($scope);

        rewind($stream);
        $output = stream_get_contents($stream);

        self::assertStringContainsString('[fail]', $output);
        self::assertStringContainsString('Dory config invalid', $output);
    }

    /**
     * @return array{CommandContext, resource}
     */
    private function buildScope(?DoryConfig $config = null): array
    {
        $config ??= new DoryConfig();
        $stream = fopen('php://memory', 'rw');
        self::assertIsResource($stream);

        $terminal = new TerminalEnvironment(isTty: false);
        $output = new StreamOutput($stream, $terminal);

        $scope = $this->createStub(CommandContext::class);
        $scope->method('service')->willReturnCallback(
            static fn(string $type) => match ($type) {
                StreamOutput::class => $output,
                DoryConfig::class => $config,
                default => throw new \RuntimeException('Unexpected service: ' . $type),
            },
        );

        return [$scope, $stream];
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Command;

use Phalanx\Archon\Command\CommandArgs;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Dory\Command\InitCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/dory-init-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function creates_hello_script_in_target_directory(): void
    {
        mkdir($this->tempDir, 0755, recursive: true);

        $scope = $this->buildScope($this->tempDir);
        $command = new InitCommand();
        $result = $command($scope);

        self::assertSame(0, $result);
        self::assertFileExists($this->tempDir . '/hello.php');

        $content = file_get_contents($this->tempDir . '/hello.php');
        self::assertIsString($content);
        self::assertStringContainsString('<?php', $content);
        self::assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function does_not_overwrite_existing_hello_script(): void
    {
        mkdir($this->tempDir, 0755, recursive: true);
        $existing = 'existing content';
        file_put_contents($this->tempDir . '/hello.php', $existing);

        [$scope, $stream] = $this->buildScopeWithStream($this->tempDir);
        $command = new InitCommand();
        $result = $command($scope);

        self::assertSame(0, $result);

        $persisted = file_get_contents($this->tempDir . '/hello.php');
        self::assertIsString($persisted);
        self::assertSame($existing, $persisted);

        rewind($stream);
        $output = stream_get_contents($stream);
        self::assertStringContainsString('already exists', $output);
    }

    #[Test]
    public function creates_directory_if_missing(): void
    {
        self::assertDirectoryDoesNotExist($this->tempDir);

        $scope = $this->buildScope($this->tempDir);
        $command = new InitCommand();
        $result = $command($scope);

        self::assertSame(0, $result);
        self::assertDirectoryExists($this->tempDir);
        self::assertFileExists($this->tempDir . '/hello.php');
    }

    #[Test]
    public function returns_zero_on_success(): void
    {
        mkdir($this->tempDir, 0755, recursive: true);

        $scope = $this->buildScope($this->tempDir);
        $command = new InitCommand();
        $result = $command($scope);

        self::assertSame(0, $result);
    }

    private function buildScope(string $directory): CommandContext
    {
        [$scope] = $this->buildScopeWithStream($directory);

        return $scope;
    }

    /**
     * @return array{CommandContext, resource}
     */
    private function buildScopeWithStream(string $directory): array
    {
        $args = new CommandArgs(['directory' => $directory]);
        $stream = fopen('php://memory', 'rw');
        self::assertIsResource($stream);

        $terminal = new TerminalEnvironment(isTty: false);
        $output = new StreamOutput($stream, $terminal);

        $scope = $this->createStub(CommandContext::class);
        $scope->method('$args::get')->willReturn($args);
        $scope->method('service')->willReturnCallback(
            static fn(string $type) => match ($type) {
                StreamOutput::class => $output,
                default => throw new \RuntimeException('Unexpected service: ' . $type),
            },
        );

        return [$scope, $stream];
    }
}

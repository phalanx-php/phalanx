<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration;

use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Input\TextInput;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end smoke test: drive Console prompts on a real Swoole-managed
 * coroutine through a kernel-tracked pipe(2) descriptor — the same fd path
 * production STDIN takes. The Runtime ConsoleInput fix in 8c5610f means we
 * pass the resource itself to waitEvent (which php_stream_casts the kernel
 * fd) rather than int-casting the PHP resource id.
 */
final class PromptIntegrationTest extends PhalanxTestCase
{
    #[Test]
    public function nonInteractiveStreamShortCircuitsToDefault(): void
    {
        $outStream = $this->outStream();
        $output = $this->streamOutput($outStream);
        $theme = $this->theme();

        $resource = Stream::memoryInput();

        try {
            $consoleInput = new ConsoleInput($resource->resource());
            $reader = new RawInput($consoleInput);

            self::assertFalse($reader->isInteractive);

            $captured = $this->scope->run(static function (ExecutionScope $scope) use (
                $output,
                $reader,
                $theme,
            ): mixed {
                return (new TextInput(
                    theme: $theme,
                    label: 'Name',
                    placeholder: '',
                    default: 'fallback',
                ))->prompt($scope, $output, $reader);
            });

            self::assertSame('fallback', $captured);
        } finally {
            $resource->close();
        }
    }

    #[Test]
    public function rawInputDrainsKernelPipeIntoCanonicalKeyTokensUnderScopedRuntime(): void
    {
        [$proc, $pipes] = self::spawnPipedChild('echo "hi\n"; fflush(STDOUT);');

        try {
            $captured = $this->scope->run(static function (ExecutionScope $scope) use ($pipes): array {
                $consoleInput = new ConsoleInput($pipes[1]);
                $reader = new RawInput($consoleInput);

                return [
                    $reader->nextKey($scope),
                    $reader->nextKey($scope),
                    $reader->nextKey($scope),
                ];
            });

            self::assertSame(['h', 'i', 'enter'], $captured);
        } finally {
            self::closePipedChild($proc, $pipes);
        }
    }

    #[Test]
    public function rawInputReturnsEmptyStringWhenPipeClosesBeforeData(): void
    {
        [$proc, $pipes] = self::spawnPipedChild('exit(0);');

        try {
            $key = $this->scope->run(static function (ExecutionScope $scope) use ($pipes): string {
                $consoleInput = new ConsoleInput($pipes[1]);
                $reader = new RawInput($consoleInput);

                return $reader->nextKey($scope);
            });

            self::assertSame('', $key);
        } finally {
            self::closePipedChild($proc, $pipes);
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private static function spawnPipedChild(string $phpSnippet): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', $phpSnippet],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private static function closePipedChild(mixed $proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }

    private function theme(): Theme
    {
        $plain = Style::new();
        return new Theme(
            success: $plain,
            warning: $plain,
            error: $plain,
            muted: $plain,
            accent: $plain,
            label: $plain,
            hint: $plain,
            border: $plain,
            active: $plain,
        );
    }

    private function outStream(): ResourceHandle
    {
        return Stream::captureBuffer();
    }

    private function streamOutput(ResourceHandle $stream): StreamOutput
    {
        return new StreamOutput($stream->resource(), new TerminalEnvironment(columns: 80, lines: 24));
    }
}

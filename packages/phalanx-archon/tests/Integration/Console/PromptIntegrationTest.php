<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Console;

use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end smoke test: drive Archon prompts on a real OpenSwoole-managed
 * coroutine through a kernel-tracked pipe(2) descriptor — the same fd path
 * production STDIN takes. The Aegis ConsoleInput fix in 8c5610f means we
 * pass the resource itself to waitEvent (which php_stream_casts the kernel
 * fd) rather than int-casting the PHP resource id.
 */
final class PromptIntegrationTest extends CoroutineTestCase
{
    #[Test]
    public function nonInteractiveStreamShortCircuitsToDefault(): void
    {
        $outStream = $this->outStream();
        $output    = $this->streamOutput($outStream);
        $theme     = $this->theme();
        $captured  = null;

        $resource = fopen('php://memory', 'r+');
        self::assertNotFalse($resource);

        try {
            $consoleInput = new ConsoleInput($resource);
            $reader       = new RawInput($consoleInput);

            self::assertFalse($reader->isInteractive);

            $this->runScoped(static function (ExecutionScope $scope) use (
                $output,
                $reader,
                $theme,
                &$captured,
            ): void {
                $captured = (new TextInput(
                    theme: $theme,
                    label: 'Name',
                    placeholder: '',
                    default: 'fallback',
                ))->prompt($scope, $output, $reader);
            });

            self::assertSame('fallback', $captured);
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function rawInputDrainsKernelPipeIntoCanonicalKeyTokensUnderAegisRuntime(): void
    {
        [$proc, $pipes] = self::spawnPipedChild('echo "hi\n"; fflush(STDOUT);');
        $captured       = [];

        try {
            $this->runScoped(static function (ExecutionScope $scope) use ($pipes, &$captured): void {
                $consoleInput = new ConsoleInput($pipes[1]);
                $reader       = new RawInput($consoleInput);

                $captured[] = $reader->nextKey($scope);
                $captured[] = $reader->nextKey($scope);
                $captured[] = $reader->nextKey($scope);
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
        $key            = 'sentinel';

        try {
            $this->runScoped(static function (ExecutionScope $scope) use ($pipes, &$key): void {
                $consoleInput = new ConsoleInput($pipes[1]);
                $reader       = new RawInput($consoleInput);

                $key = $reader->nextKey($scope);
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
        $proc  = proc_open(
            [PHP_BINARY, '-r', $phpSnippet],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);
        self::assertIsResource($pipes[1]);

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
            error:   $plain,
            muted:   $plain,
            accent:  $plain,
            label:   $plain,
            hint:    $plain,
            border:  $plain,
            active:  $plain,
        );
    }

    /** @return resource */
    private function outStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');
        self::assertNotFalse($stream);
        return $stream;
    }

    private function streamOutput(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }
}

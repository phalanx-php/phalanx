<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Console\Widget;

use InvalidArgumentException;
use Phalanx\Archon\Console\Output\LiveRegionRenderer;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\ConcurrentTaskList;
use Phalanx\Archon\Tests\Support\RecordingLiveRegionWriter;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ConcurrentTaskListTest extends PhalanxTestCase
{
    #[Test]
    public function successPathRendersAllTasksAsSucceeded(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();
        $writer = new RecordingLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $this->scope->run(static function (ExecutionScope $scope) use ($output, $theme, $renderer): void {
            $okOne = new class implements Scopeable {
                public function __invoke(Scope $scope): string
                {
                    return 'ok-1';
                }
            };
            $okTwo = new class implements Scopeable {
                public function __invoke(Scope $scope): string
                {
                    return 'ok-2';
                }
            };

            new ConcurrentTaskList($scope, $output, $theme, renderer: $renderer)
                ->add('a', 'Alpha', $okOne)
                ->add('b', 'Beta', $okTwo)
                ->run();
        });

        $rendered = implode("\n", $writer->persists[0]);
        self::assertNotSame([], $writer->updates);
        self::assertCount(1, $writer->persists);
        self::assertStringContainsString('Alpha', $rendered);
        self::assertStringContainsString('Beta', $rendered);
        self::assertSame(2, substr_count($rendered, '✓'));
        self::assertStringNotContainsString('✗', $rendered);
    }

    #[Test]
    public function perTaskFailureRendersErrorIconWithMessage(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();
        $writer = new RecordingLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $this->scope->run(static function (ExecutionScope $scope) use ($output, $theme, $renderer): void {
            $okTask = new class implements Scopeable {
                public function __invoke(Scope $scope): string
                {
                    return 'ok';
                }
            };
            $failTask = new class implements Scopeable {
                public function __invoke(\Phalanx\Scope\Scope $scope): never
                {
                    throw new RuntimeException('boom');
                }
            };

            new ConcurrentTaskList($scope, $output, $theme, renderer: $renderer)
                ->add('ok', 'OK Task', $okTask)
                ->add('fail', 'Fail Task', $failTask)
                ->run();
        });

        $rendered = implode("\n", $writer->persists[0]);
        self::assertStringContainsString('OK Task', $rendered);
        self::assertStringContainsString('Fail Task', $rendered);
        self::assertStringContainsString('✓', $rendered);
        self::assertStringContainsString('✗', $rendered);
        self::assertStringContainsString('boom', $rendered);
    }

    #[Test]
    public function delayedTaskAllowsPeriodicLiveUpdatesBeforeFinalSettle(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();
        $writer = new RecordingLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $this->scope->run(static function (ExecutionScope $scope) use ($output, $theme, $renderer): void {
            $task = new class implements Executable {
                public function __invoke(ExecutionScope $scope): string
                {
                    $scope->delay(0.04);

                    return 'ok';
                }
            };

            new ConcurrentTaskList($scope, $output, $theme, spinnerFps: 100, renderer: $renderer)
                ->add('slow', 'Slow Task', $task)
                ->run();
        });

        self::assertGreaterThan(1, count($writer->updates));
        self::assertCount(1, $writer->persists);
    }

    #[Test]
    public function emptyTaskListIsNoOp(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();
        $writer = new RecordingLiveRegionWriter();
        $renderer = new LiveRegionRenderer($writer);

        $this->scope->run(static function (ExecutionScope $scope) use ($output, $theme, $renderer): void {
            new ConcurrentTaskList($scope, $output, $theme, renderer: $renderer)->run();
        });

        self::assertSame('', $this->contents($stream));
        self::assertSame([], $writer->updates);
        self::assertSame([], $writer->persists);
    }

    #[Test]
    public function spinnerFpsMustBeGreaterThanZero(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConcurrentTaskList spinner FPS must be greater than zero.');

        $this->scope->run(static function (ExecutionScope $scope) use ($output, $theme): void {
            new ConcurrentTaskList($scope, $output, $theme, spinnerFps: 0);
        });
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
    private function stream(): mixed
    {
        $stream = fopen('php://temp', 'w+');
        self::assertNotFalse($stream);
        return $stream;
    }

    private function streamOutput(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }

    private function contents(mixed $stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}

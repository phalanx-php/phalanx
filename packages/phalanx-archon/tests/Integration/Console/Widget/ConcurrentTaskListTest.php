<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Console\Widget;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\ConcurrentTaskList;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ConcurrentTaskListTest extends CoroutineTestCase
{
    #[Test]
    public function successPathRendersAllTasksAsSucceeded(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            $okOne = new class implements Scopeable {
                public function __invoke(\Phalanx\Scope\Scope $scope): string
                {
                    return 'ok-1';
                }
            };
            $okTwo = new class implements Scopeable {
                public function __invoke(\Phalanx\Scope\Scope $scope): string
                {
                    return 'ok-2';
                }
            };

            (new ConcurrentTaskList($scope, $output, $theme))
                ->add('a', 'Alpha', $okOne)
                ->add('b', 'Beta', $okTwo)
                ->run();
        });

        $rendered = $this->contents($stream);
        self::assertStringContainsString('Alpha', $rendered);
        self::assertStringContainsString('Beta', $rendered);
        self::assertStringContainsString('✓', $rendered);
    }

    #[Test]
    public function perTaskFailureRendersErrorIconWithMessage(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            $okTask = new class implements Scopeable {
                public function __invoke(\Phalanx\Scope\Scope $scope): string
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

            (new ConcurrentTaskList($scope, $output, $theme))
                ->add('ok', 'OK Task', $okTask)
                ->add('fail', 'Fail Task', $failTask)
                ->run();
        });

        $rendered = $this->contents($stream);
        self::assertStringContainsString('OK Task', $rendered);
        self::assertStringContainsString('Fail Task', $rendered);
        self::assertStringContainsString('✓', $rendered);
        self::assertStringContainsString('✗', $rendered);
        self::assertStringContainsString('boom', $rendered);
    }

    #[Test]
    public function emptyTaskListIsNoOp(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            (new ConcurrentTaskList($scope, $output, $theme))->run();
        });

        self::assertSame('', $this->contents($stream));
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

<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Scan;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\ProgressBar;
use Phalanx\Archon\Console\Widget\Table;
use Phalanx\Archon\Scan\ScanProgress;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ScanProgressTest extends CoroutineTestCase
{
    #[Test]
    public function startThenDonePersistsHeaderAndFooter(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            $progress = new ScanProgress(
                $scope,
                static fn(mixed $hit): array => [(string) $hit, ''],
                $output,
                new ProgressBar($theme),
                new Table($theme),
                $theme,
                ['Result'],
            );

            $progress->onStart(0);
            $progress->onDone(1.5);
        });

        $rendered = $this->contents($stream);
        self::assertStringContainsString('Result', $rendered);
        self::assertStringContainsString('Found 0/0 in 1.5s', $rendered);
    }

    #[Test]
    public function hitPersistsRowAndDoneIncludesCount(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            $progress = new ScanProgress(
                $scope,
                static fn(mixed $hit): array => [(string) $hit, 'detail'],
                $output,
                new ProgressBar($theme),
                new Table($theme),
                $theme,
                ['Result', 'Detail'],
            );

            $progress->onStart(5);
            $progress->onHit('alpha');
            $progress->onMiss(null);
            $progress->onMiss(null);
            $progress->onHit('beta');
            $progress->onDone(2.0);
        });

        $rendered = $this->contents($stream);
        self::assertStringContainsString('alpha', $rendered);
        self::assertStringContainsString('beta', $rendered);
        self::assertStringContainsString('Found 2/5 in 2.0s', $rendered);
    }

    #[Test]
    public function nonTtyEmitsCountLineEveryTenMisses(): void
    {
        $stream = $this->stream();
        $output = $this->streamOutput($stream);
        $theme  = $this->theme();

        $this->runScoped(static function (ExecutionScope $scope) use ($output, $theme): void {
            $progress = new ScanProgress(
                $scope,
                static fn(mixed $hit): array => [(string) $hit, ''],
                $output,
                new ProgressBar($theme),
                new Table($theme),
                $theme,
                ['Result'],
            );

            $progress->onStart(20);
            for ($i = 0; $i < 20; $i++) {
                $progress->onMiss(null);
            }
            $progress->onDone(0.5);
        });

        $rendered = $this->contents($stream);
        self::assertStringContainsString('Checking... 10/20', $rendered);
        self::assertStringContainsString('Checking... 20/20', $rendered);
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

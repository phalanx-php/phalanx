<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\SourcePreview;
use Phalanx\Supervisor\TaskTreeFormatter;
use Throwable;

/**
 * High-fidelity terminal error output.
 *
 * Renders a bold red error block followed by a syntax-highlighted source
 * preview, a hierarchical task tree snapshot, and a clean stack trace.
 */
final readonly class DefaultConsoleErrorRenderer implements ConsoleErrorRenderer
{
    private const string MARGIN = '  ';

    public function __construct(private bool $debug = false)
    {
    }

    public function render(CommandScope $scope, Throwable $e, StreamOutput $output): bool
    {
        $theme = $scope->service(Theme::class);
        $muted = $theme->muted;
        $accent = $theme->accent;

        $file = $e->getFile();
        $line = $e->getLine();

        // 1. Header & Brand
        $output->persist("\n");
        $output->persist(self::MARGIN . Style::new()->bg('red')->fg('white')->bold()->apply(" ERROR "));
        $output->persist(self::MARGIN . $theme->error->apply($e->getMessage()));

        // 2. Source Context
        $output->persist("\n" . self::MARGIN . $muted->apply("Source: ") . $accent->apply($file . ':' . $line));
        $output->persist((new SourcePreview($theme))->render($file, $line));

        if ($this->debug) {
            // 3. Active Ledger (Concurrency Graph)
            if ($scope instanceof \Phalanx\Scope\ExecutionScope) {
                $output->persist(self::MARGIN . $muted->apply("Active Ledger Snapshot:"));
                try {
                    $tree = (new TaskTreeFormatter())->format($scope->supervisor()->tree());
                    foreach (explode("\n", rtrim($tree)) as $treeLine) {
                        $output->persist(self::MARGIN . $treeLine);
                    }
                    $output->persist("");
                } catch (\Phalanx\Cancellation\Cancelled $c) {
                    throw $c;
                } catch (Throwable) {
                    $output->persist(self::MARGIN . $muted->apply("(Task tree unavailable)") . "\n");
                }
            }

            // 4. Trace Path
            $output->persist(self::MARGIN . $muted->apply("Stack Trace:"));
            $this->renderTrace($output, $theme, $e);
        }

        $output->persist("\n");

        return true;
    }

    private function renderTrace(StreamOutput $output, Theme $theme, Throwable $e): void
    {
        $frames = $e->getTrace();
        $muted = $theme->muted;
        $accent = $theme->accent;
        $funcStyle = Style::new()->fg('yellow');

        foreach (array_slice($frames, 0, 10) as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $func = $frame['function'];

            $output->persist(sprintf(
                "%s %s %s%s%s() %s %s\n",
                self::MARGIN,
                $muted->apply(sprintf('%d.', $i + 1)),
                $funcStyle->apply($class . $type . $func),
                '',
                '',
                $muted->apply('at'),
                $accent->apply(basename($file) . ':' . $line)
            ));
        }
    }
}

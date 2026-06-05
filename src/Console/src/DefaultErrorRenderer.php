<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Widget\SourcePreview;
use Phalanx\Supervisor\TaskRunSnapshot;
use Phalanx\Supervisor\TaskTreeFormatter;
use Throwable;

/**
 * High-fidelity terminal error output.
 *
 * Renders a bold red error block followed by a syntax-highlighted source
 * preview, a hierarchical task tree snapshot, and a clean stack trace.
 */
final readonly class DefaultErrorRenderer implements ErrorRenderer
{
    private const string MARGIN = '  ';

    /** @param list<TaskRunSnapshot> $diagnosticTree */
    public function __construct(
        private bool $debug = false,
        private array $diagnosticTree = [],
    ) {
    }

    public function render(CommandContext $ctx, Throwable $e, StreamOutput $output): bool
    {
        $theme = $ctx->service(Theme::class);
        $muted = $theme->muted;
        $accent = $theme->accent;

        $file = $e->getFile();
        $line = $e->getLine();

        $output->persist("\n");
        $output->persist(self::MARGIN . Style::new()->bg('red')->fg('white')->bold()->apply(" ERROR "));
        $output->persist(self::MARGIN . $theme->error->apply($e->getMessage()));

        $output->persist("\n" . self::MARGIN . $muted->apply("Source: ") . $accent->apply($file . ':' . $line));
        $output->persist((new SourcePreview($theme))->render($file, $line));

        if ($this->debug) {
            $output->persist(self::MARGIN . $muted->apply("Active Ledger Snapshot:"));
            $tree = (new TaskTreeFormatter())->format($this->diagnosticTree);
            foreach (explode("\n", rtrim($tree)) as $treeLine) {
                $output->persist(self::MARGIN . $treeLine);
            }
            $output->persist("");

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

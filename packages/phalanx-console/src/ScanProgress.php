<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Closure;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Widget\ProgressBar;
use Phalanx\Console\Widget\Spinner;
use Phalanx\Console\Widget\Table;
use Phalanx\Console\Style\Theme;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use WeakReference;

/**
 * Generic scan progress observer. Renders a streaming table with a live
 * spinner + progress bar on the line below each row.
 *
 * Timer lifecycle:
 *   onStart  → starts spinner timer
 *   onHit    → stops timer, persists row, updates live line, restarts timer
 *   onMiss   → timer already handles the live line tick (no extra write)
 *   onDone   → stops timer, clears live region, persists footer summary
 *
 * The timer closure uses WeakReference to break the reference cycle:
 *   ScanProgress → TimerInterface → Closure → ScanProgress
 * Without it, the scan object would stay pinned until the loop exits.
 *
 * @template T
 */
final class ScanProgress implements ScanObserver
{
    private int $checked     = 0;
    private int $found       = 0;
    private ?int $total      = null;
    private int $spinnerTick = 0;

    /** @var list<int> */
    private array $widths = [];

    private ?TimerInterface $timer = null;
    private Spinner $spinner;

    /**
     * @param Closure(mixed): array{0: string, 1: string} $formatHit
     *   Receives the raw hit result, returns [label, detail].
     *   Pass '' for detail to omit the second column.
     * @param list<string> $headers  Column headers for the hit table.
     */
    public function __construct(
        private readonly Closure $formatHit,
        private readonly StreamOutput $output,
        private readonly ProgressBar $progressBar,
        private readonly Table $table,
        private readonly Theme $theme,
        private readonly array $headers = ['Result', 'Detail'],
    ) {
        $this->spinner = new Spinner($theme, Spinner::DOTS);
    }

    public function onStart(int $total): void
    {
        $this->total  = $total;
        $this->widths = Table::computeWidths($this->headers, [], $this->output->width());

        $this->output->persist($this->table->header($this->headers, $this->widths));
        $this->startTimer();
    }

    public function onHit(mixed $result): void
    {
        $this->found++;
        $this->checked++;

        [$label, $detail] = ($this->formatHit)($result);

        // Stop the timer for the duration of the persist+update pair.
        // This prevents the timer from firing mid-write and producing a
        // torn frame where both the row and the live line change simultaneously.
        $this->stopTimer();
        $this->output->persist($this->table->row([$label, $detail], $this->widths));
        $this->output->update($this->buildLiveLine());
        $this->startTimer();
    }

    public function onMiss(mixed $result): void
    {
        $this->checked++;
        // The periodic timer handles live line updates for TTY.
        // For non-TTY (CI / pipes) emit a count line every 10 items so
        // there's some visible progress without spamming every miss.
        if (!$this->output->isTty() && $this->checked % 10 === 0) {
            $this->output->persist($this->countLine());
        }
    }

    public function onDone(float $elapsed): void
    {
        $this->stopTimer();
        $this->output->clear();

        $denominator = $this->total !== null ? "/{$this->total}" : '';
        $summary     = sprintf('Found %d%s in %.1fs', $this->found, $denominator, $elapsed);

        $this->output->persist($this->table->footer($this->widths, $summary));
    }

    private function buildLiveLine(): string
    {
        $spinFrame = $this->spinner->frame($this->spinnerTick);
        // Reserve 4 chars for "  ⠋  " prefix (2 indent + spinner + 2 spaces)
        $bar = $this->progressBar->render(
            $this->checked,
            $this->total ?? $this->checked,
            $this->output->width() - 6,
        );

        if ($this->total !== null) {
            $counts = $this->theme->muted->apply(
                sprintf('  %d/%d', $this->checked, $this->total),
            );
            return "  {$spinFrame}  {$bar}{$counts}";
        }

        return "  {$spinFrame}  {$bar}";
    }

    private function countLine(): string
    {
        $denominator = $this->total !== null ? "/{$this->total}" : '';
        return sprintf('  Checking... %d%s', $this->checked, $denominator);
    }

    private function startTimer(): void
    {
        $ref = WeakReference::create($this);

        // Static closure + Closure::bind(null, ScanProgress::class) combination:
        //   - Static → no implicit $this capture, no reference cycle
        //   - Class scope → allowed to access private members ($spinnerTick,
        //     $output, buildLiveLine()) on the instance retrieved from the WeakRef
        //   - WeakReference → timer closure does not pin ScanProgress in memory
        /** @var Closure(): void $tick */
        $tick = Closure::bind(
            static function () use ($ref): void {
                $self = $ref->get();
                if ($self === null) {
                    return;
                }
                $self->spinnerTick++;
                $self->output->update($self->buildLiveLine());
            },
            null,
            self::class,
        );

        $this->timer = Loop::addPeriodicTimer(0.08, $tick);
    }

    private function stopTimer(): void
    {
        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }
    }
}

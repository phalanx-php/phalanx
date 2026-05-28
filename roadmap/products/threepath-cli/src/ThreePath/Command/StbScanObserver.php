<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\ScanObserver;
use Phalanx\Archon\Style\Theme;
use Phalanx\Archon\Widget\Spinner;
use Phalanx\Archon\Widget\Table;
use ThreePath\StbResponse;

final class StbScanObserver implements ScanObserver
{
    /** @var list<int> */
    private array $widths = [];
    private int $found   = 0;
    private int $total   = 0;
    private int $checked = 0;
    private int $tick    = 0;
    private Spinner $spinner;

    public function __construct(
        private readonly StreamOutput $output,
        private readonly Table $table,
        Theme $theme,
    ) {
        $this->spinner = new Spinner($theme, Spinner::DOTS);
    }

    public function onStart(int $total): void
    {
        $this->total  = $total;
        $ipWidth      = 15; // max IPv4 length
        $detailWidth  = max(27, $this->output->width() - $ipWidth - 5); // indent(2) + separator(3)
        $this->widths = [$ipWidth, $detailWidth];
        $this->output->persist($this->table->header(['IP', 'Details'], $this->widths));
        $this->output->update($this->progressLine());
    }

    public function onHit(mixed $result): void
    {
        /** @var StbResponse $result */
        $this->found++;
        $this->checked++;
        $fw     = $result->get('apk_version');
        $detail = "chip={$result->chipId}" . ($fw !== null ? "  fw={$fw}" : '');
        $this->output->persist($this->table->row([$result->ip, $detail], $this->widths));
        $this->output->update($this->progressLine());
    }

    public function onMiss(mixed $result): void
    {
        $this->checked++;
        $this->tick++;
        $this->output->update($this->progressLine());
    }

    public function onDone(float $elapsed): void
    {
        $this->output->clear();
        $summary = "Found {$this->found}/{$this->total} in " . number_format($elapsed, 1) . 's';
        $this->output->persist($this->table->footer($this->widths, $summary));
    }

    private function progressLine(): string
    {
        $pct = $this->total > 0
            ? (int) round($this->checked / $this->total * 100)
            : 0;
        return '  ' . $this->spinner->frame($this->tick)
            . "  {$this->checked}/{$this->total}  ({$pct}%)";
    }
}

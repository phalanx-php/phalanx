<?php

declare(strict_types=1);

namespace Phalanx\Tui\Drawing;

use Generator;
use Phalanx\Tui\Drawing\Buffer;
use Phalanx\Tui\Drawing\BufferUpdate;
use Phalanx\Tui\Drawing\Cell;
use Phalanx\Tui\Styles\Color;
use Phalanx\Tui\Styles\ColorMode;
use Phalanx\Tui\Styles\Modifier;
use Phalanx\Tui\Styles\Style;

final class AnsiWriter
{
    private int $lastX = -1;
    private int $lastY = -1;

    private ?Color $activeFg = null;
    private ?Color $activeBg = null;
    private int $activeMods = 0;

    /** @var resource */
    private $stream;

    public function __construct(
        private(set) ColorMode $colorMode = ColorMode::Ansi24,
        private(set) bool $syncOutput = true,
        mixed $stream = null,
        private ?string $captureFile = null,
        private bool $fullSgr = false,
    ) {
        $this->stream = $stream ?? STDOUT;
        stream_set_write_buffer($this->stream, 0);

        if ($this->captureFile !== null) {
            file_put_contents($this->captureFile, '');
        }
    }

    /** @param Generator<int, array{int, int, Cell}> $diff */
    public function flushDiff(Generator $diff): int
    {
        $output = '';
        $count = 0;

        if ($this->syncOutput) {
            $output .= "\033[?2026h";
        }

        foreach ($diff as [$x, $y, $cell]) {
            if ($y !== $this->lastY || $x !== $this->lastX + 1) {
                $output .= self::moveCursor($x, $y);
            }

            $output .= $this->sgrDelta($cell->style);
            $output .= $cell->char;

            $this->lastX = $x;
            $this->lastY = $y;
            $count++;
        }

        if ($this->syncOutput) {
            $output .= "\033[?2026l";
        }

        if ($count > 0) {
            $this->write($output);
        }

        return $count;
    }

    public function renderDiff(Buffer $current, Buffer $previous): int
    {
        $curCells = $current->cells();
        $prevCells = $previous->cells();
        $w = $current->width;
        $curCount = count($curCells);
        $shared = min($curCount, count($prevCells));
        $output = '';
        $count = 0;

        if ($this->syncOutput) {
            $output .= "\033[?2026h";
        }

        for ($i = 0; $i < $shared; $i++) {
            $cell = $curCells[$i];

            if ($cell->skipDiff) {
                continue;
            }

            if (!$cell->equals($prevCells[$i])) {
                $x = $i % $w;
                $y = intdiv($i, $w);

                if ($y !== $this->lastY || $x !== $this->lastX + 1) {
                    $output .= self::moveCursor($x, $y);
                }

                $output .= $this->sgrDelta($cell->style);
                $output .= $cell->char;
                $this->lastX = $x;
                $this->lastY = $y;
                $count++;
            }
        }

        for ($i = $shared; $i < $curCount; $i++) {
            $cell = $curCells[$i];

            if ($cell->skipDiff) {
                continue;
            }

            $x = $i % $w;
            $y = intdiv($i, $w);

            if ($y !== $this->lastY || $x !== $this->lastX + 1) {
                $output .= self::moveCursor($x, $y);
            }

            $output .= $this->sgrDelta($cell->style);
            $output .= $cell->char;
            $this->lastX = $x;
            $this->lastY = $y;
            $count++;
        }

        if ($this->syncOutput) {
            $output .= "\033[?2026l";
        }

        if ($count > 0) {
            $this->write($output);
        }

        return $count;
    }

    /** @param list<BufferUpdate> $updates */
    public function flush(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $output = '';

        if ($this->syncOutput) {
            $output .= "\033[?2026h";
        }

        foreach ($updates as $update) {
            if ($update->y !== $this->lastY || $update->x !== $this->lastX + 1) {
                $output .= self::moveCursor($update->x, $update->y);
            }

            $output .= $this->sgrDelta($update->style);
            $output .= $update->char;

            $this->lastX = $update->x;
            $this->lastY = $update->y;
        }

        if ($this->syncOutput) {
            $output .= "\033[?2026l";
        }

        $this->write($output);
    }

    public function hideCursor(): void
    {
        $this->write("\033[?25l");
    }

    public function showCursor(): void
    {
        $this->write("\033[?25h");
    }

    public function moveTo(int $x, int $y): void
    {
        $this->write(self::moveCursor($x, $y));
    }

    public function enterAlternateScreen(): void
    {
        $this->write("\033[?1049h");
    }

    public function leaveAlternateScreen(): void
    {
        $this->write("\033[?1049l");
    }

    public function clearScreen(): void
    {
        $this->write("\033[2J");
    }

    public function enableMouseTracking(): void
    {
        $this->write("\033[?1003h\033[?1006h");
    }

    public function disableMouseTracking(): void
    {
        $this->write("\033[?1003l\033[?1006l");
    }

    public function enableBracketedPaste(): void
    {
        $this->write("\033[?2004h");
    }

    public function disableBracketedPaste(): void
    {
        $this->write("\033[?2004l");
    }

    public function resetState(): void
    {
        $this->lastX = -1;
        $this->lastY = -1;
        $this->activeFg = null;
        $this->activeBg = null;
        $this->activeMods = 0;
        $this->write("\033[0m");
    }

    private static function moveCursor(int $x, int $y): string
    {
        return "\033[" . ($y + 1) . ';' . ($x + 1) . 'H';
    }

    private function sgrDelta(Style $style): string
    {
        if ($this->fullSgr) {
            $sgr = $style->sgr($this->colorMode);

            return $sgr !== '' ? "\033[0m" . $sgr : "\033[0m";
        }

        $newFg = $style->foreground;
        $newBg = $style->background;
        $newMods = $style->modifierBits;

        $fgSame = ($this->activeFg === null && $newFg === null)
            || ($this->activeFg !== null && $newFg !== null && $this->activeFg->equals($newFg));
        $bgSame = ($this->activeBg === null && $newBg === null)
            || ($this->activeBg !== null && $newBg !== null && $this->activeBg->equals($newBg));
        $modsSame = $this->activeMods === $newMods;

        if ($fgSame && $bgSame && $modsSame) {
            return '';
        }

        $removedMods = $this->activeMods & ~$newMods;
        $removedFg = $this->activeFg !== null && $newFg === null;
        $removedBg = $this->activeBg !== null && $newBg === null;

        if ($removedMods !== 0 || $removedFg || $removedBg) {
            $this->activeFg = null;
            $this->activeBg = null;
            $this->activeMods = 0;

            $codes = ['0'];

            foreach (Modifier::cases() as $mod) {
                if ($newMods & $mod->value) {
                    $codes[] = (string) $mod->sgr();
                }
            }

            if ($newFg !== null) {
                $codes[] = $newFg->toSgr($this->colorMode, foreground: true);
            }

            if ($newBg !== null) {
                $codes[] = $newBg->toSgr($this->colorMode, foreground: false);
            }

            $this->activeFg = $newFg;
            $this->activeBg = $newBg;
            $this->activeMods = $newMods;

            return "\033[" . implode(';', $codes) . 'm';
        }

        $codes = [];

        $addedMods = $newMods & ~$this->activeMods;
        foreach (Modifier::cases() as $mod) {
            if ($addedMods & $mod->value) {
                $codes[] = (string) $mod->sgr();
            }
        }

        if (!$fgSame && $newFg !== null) {
            $codes[] = $newFg->toSgr($this->colorMode, foreground: true);
        }

        if (!$bgSame && $newBg !== null) {
            $codes[] = $newBg->toSgr($this->colorMode, foreground: false);
        }

        $this->activeFg = $newFg;
        $this->activeBg = $newBg;
        $this->activeMods = $newMods;

        if ($codes === []) {
            return '';
        }

        return "\033[" . implode(';', $codes) . 'm';
    }

    private function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        $len = strlen($data);
        $written = 0;
        $stalls = 0;

        while ($written < $len) {
            $result = @fwrite($this->stream, substr($data, $written));

            if ($result === false) {
                throw new \RuntimeException('Failed writing ANSI output to terminal');
            }

            if ($result === 0) {
                fflush($this->stream);
                usleep(1_000);

                if (++$stalls > 1_000) {
                    throw new \RuntimeException('Timed out writing ANSI output to terminal');
                }

                continue;
            }

            $stalls = 0;
            $written += $result;
        }

        fflush($this->stream);

        if ($this->captureFile !== null) {
            file_put_contents($this->captureFile, $data, FILE_APPEND);
        }
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Writer;

use Phalanx\Terminal\Buffer\BufferUpdate;
use Phalanx\Terminal\Style\ColorMode;
use Phalanx\Terminal\Style\Style;

final class AnsiWriter
{
    private int $lastX = -1;
    private int $lastY = -1;
    private ?Style $lastStyle = null;

    /** @var resource */
    private $stream;

    public function __construct(
        private ColorMode $colorMode = ColorMode::Ansi24,
        mixed $stream = null,
    ) {
        $this->stream = $stream ?? \STDOUT;
    }

    /** @param list<BufferUpdate> $updates */
    public function flush(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $output = '';

        foreach ($updates as $update) {
            if ($update->y !== $this->lastY || $update->x !== $this->lastX + 1) {
                $output .= self::moveCursor($update->x, $update->y);
            }

            if ($this->lastStyle === null || !$update->style->equals($this->lastStyle)) {
                $output .= "\033[0m";
                $sgr = $update->style->sgr($this->colorMode);

                if ($sgr !== '') {
                    $output .= $sgr;
                }

                $this->lastStyle = $update->style;
            }

            $output .= $update->char;
            $this->lastX = $update->x;
            $this->lastY = $update->y;
        }

        $output .= "\033[0m";

        fwrite($this->stream, $output);
    }

    public function hideCursor(): void
    {
        fwrite($this->stream, "\033[?25l");
    }

    public function showCursor(): void
    {
        fwrite($this->stream, "\033[?25h");
    }

    public function moveTo(int $x, int $y): void
    {
        fwrite($this->stream, self::moveCursor($x, $y));
    }

    public function enterAlternateScreen(): void
    {
        fwrite($this->stream, "\033[?1049h");
    }

    public function leaveAlternateScreen(): void
    {
        fwrite($this->stream, "\033[?1049l");
    }

    public function clearScreen(): void
    {
        fwrite($this->stream, "\033[2J");
    }

    public function enableMouseTracking(): void
    {
        fwrite($this->stream, "\033[?1003h\033[?1006h");
    }

    public function disableMouseTracking(): void
    {
        fwrite($this->stream, "\033[?1003l\033[?1006l");
    }

    public function enableBracketedPaste(): void
    {
        fwrite($this->stream, "\033[?2004h");
    }

    public function disableBracketedPaste(): void
    {
        fwrite($this->stream, "\033[?2004l");
    }

    public function resetState(): void
    {
        $this->lastX = -1;
        $this->lastY = -1;
        $this->lastStyle = null;
    }

    private static function moveCursor(int $x, int $y): string
    {
        return "\033[" . ($y + 1) . ';' . ($x + 1) . 'H';
    }
}

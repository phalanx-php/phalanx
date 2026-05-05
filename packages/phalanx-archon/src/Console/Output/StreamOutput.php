<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Output;

/**
 * Single point of contact with the terminal for all console output.
 *
 * Two write modes:
 *   persist() — content becomes permanent scrollback, cursor advances past it
 *   update()  — rewrites the same "live region" in place, no trailing newline
 *
 * All writes are wrapped in synchronized output mode (\033[?2026h/l) so
 * terminal emulators paint atomically and eliminate flicker.
 *
 * Non-TTY: update() is transient and clear() is a no-op. Permanent output
 * still goes through persist(), so pipes/CI get stable lines instead of every
 * spinner frame.
 */
final class StreamOutput implements LiveRegionWriter
{
    private int $lastVisualRowCount = 0;
    private bool $isTty;
    private bool $syncSupported;
    private int $cachedWidth;
    private int $cachedHeight;
    private TerminalEnvironment $terminal;

    /** @param resource $stream */
    public function __construct(
        private mixed $stream = STDOUT,
        ?TerminalEnvironment $terminal = null,
    ) {
        if ($terminal === null) {
            $terminal = new TerminalEnvironment();
        }

        $this->terminal = $terminal;
        $this->isTty = $this->terminal->isTty ?? stream_isatty($this->stream);

        /**
         * Apple Terminal does not support CSI ?2026 synchronized output. The
         * sequences appear literally in scrollback history, so suppress them.
         */
        $this->syncSupported = $this->terminal->termProgram !== 'Apple_Terminal';

        $this->cachedWidth  = $this->measureWidth();
        $this->cachedHeight = $this->measureHeight();
    }

    /**
     * Write lines permanently — content moves into scrollback.
     * Atomically erases any live region and writes with a trailing newline.
     */
    public function persist(string ...$lines): void
    {
        if ($this->isTty && $this->syncSupported) {
            fwrite($this->stream, "\033[?2026h");
        }
        $this->eraseRegion();
        fwrite($this->stream, implode("\n", $lines));
        fwrite($this->stream, "\n");
        if ($this->isTty && $this->syncSupported) {
            fwrite($this->stream, "\033[?2026l");
        }

        $this->lastVisualRowCount = 0;
    }

    /**
     * Rewrite the live region in place — no trailing newline.
     * Cursor stays on the last line so the next update() knows where to erase from.
     *
     * Non-TTY: drops the transient frame. Callers should persist the final
     * state or explicit checkpoints they want in logs.
     */
    public function update(string ...$lines): void
    {
        if (!$this->isTty) {
            return;
        }

        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026h");
        }
        $this->eraseRegion();
        $rendered = implode("\n", $lines);
        fwrite($this->stream, $rendered);
        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026l");
        }

        $this->lastVisualRowCount = $this->visualRowCount($rendered);
    }

    /**
     * Erase the live region and leave the cursor on a fresh line.
     * Call when transitioning away from an update() region entirely.
     */
    public function clear(): void
    {
        if (!$this->isTty || $this->lastVisualRowCount === 0) {
            return;
        }

        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026h");
        }
        $this->eraseRegion();
        fwrite($this->stream, "\n");
        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026l");
        }

        $this->lastVisualRowCount = 0;
    }

    public function width(): int
    {
        return $this->cachedWidth;
    }

    public function height(): int
    {
        return $this->cachedHeight;
    }

    public function isTty(): bool
    {
        return $this->isTty;
    }

    /**
     * Move cursor to the start of the live region and erase downward.
     * Must be called inside a synchronized output window.
     */
    private function eraseRegion(): void
    {
        if ($this->lastVisualRowCount === 0) {
            return;
        }

        if ($this->lastVisualRowCount > 1) {
            fwrite($this->stream, sprintf("\033[%dA", $this->lastVisualRowCount - 1));
        }

        fwrite($this->stream, "\r\033[J");
    }

    private function visualRowCount(string $rendered): int
    {
        $columns = max(1, $this->cachedWidth);
        $rows = 0;

        foreach (explode("\n", $rendered) as $line) {
            $width = $this->displayWidth($line);
            $rows += intdiv(max(0, $width - 1), $columns) + 1;
        }

        return max(1, $rows);
    }

    private function displayWidth(string $line): int
    {
        $plain = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $line) ?? $line;

        return mb_strwidth($plain);
    }

    private function measureWidth(): int
    {
        if ($this->terminal->columns !== null) {
            return $this->terminal->columns;
        }

        return 80;
    }

    private function measureHeight(): int
    {
        if ($this->terminal->lines !== null) {
            return $this->terminal->lines;
        }

        return 24;
    }
}

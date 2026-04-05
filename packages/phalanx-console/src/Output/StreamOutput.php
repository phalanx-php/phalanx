<?php

declare(strict_types=1);

namespace Phalanx\Console\Output;

use WeakReference;

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
 * Non-TTY: update() degrades to persist(), clear() is a no-op. Safe for pipes/CI.
 */
final class StreamOutput
{
    private int $lastLineCount = 0;
    private bool $isTty;
    private bool $syncSupported;
    private int $cachedWidth;
    private int $cachedHeight;

    /** @param resource $stream */
    public function __construct(private mixed $stream = STDOUT)
    {
        $this->isTty = stream_isatty($this->stream);

        // Apple Terminal does not support CSI ?2026 (synchronized output) and the
        // sequences appear literally in scrollback history. Suppress them there.
        $this->syncSupported = ($_SERVER['TERM_PROGRAM'] ?? '') !== 'Apple_Terminal';

        // Compute terminal dimensions once at construction (acceptable blocking call
        // at boot before the event loop starts). SIGWINCH invalidates and recomputes
        // synchronously so width()/height() never block during rendering.
        $this->cachedWidth  = $this->measureWidth();
        $this->cachedHeight = $this->measureHeight();

        if ($this->isTty && function_exists('pcntl_signal')) {
            // WeakReference breaks the $this capture cycle. If StreamOutput is ever
            // eligible for GC the signal handler becomes a no-op instead of pinning it.
            $ref = WeakReference::create($this);
            pcntl_signal(SIGWINCH, static function () use ($ref): void {
                $self = $ref->get();
                if ($self === null) {
                    return;
                }
                // Recompute synchronously here so subsequent width()/height() calls
                // during the next render cycle return the updated values without
                // issuing any blocking shell_exec inside the event loop.
                $self->cachedWidth  = $self->measureWidth();
                $self->cachedHeight = $self->measureHeight();
            });
        }
    }

    /**
     * Write lines permanently — content moves into scrollback.
     * Atomically erases any live region and writes with a trailing newline.
     */
    public function persist(string ...$lines): void
    {
        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026h");
        }
        $this->eraseRegion();
        fwrite($this->stream, implode("\n", $lines));
        fwrite($this->stream, "\n");
        if ($this->syncSupported) {
            fwrite($this->stream, "\033[?2026l");
        }

        $this->lastLineCount = 0;
    }

    /**
     * Rewrite the live region in place — no trailing newline.
     * Cursor stays on the last line so the next update() knows where to erase from.
     *
     * Non-TTY: delegates to persist() so piped output is still readable.
     */
    public function update(string ...$lines): void
    {
        if (!$this->isTty) {
            $this->persist(...$lines);
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

        $this->lastLineCount = substr_count($rendered, "\n") + 1;
    }

    /**
     * Erase the live region and leave the cursor on a fresh line.
     * Call when transitioning away from an update() region entirely.
     */
    public function clear(): void
    {
        if (!$this->isTty || $this->lastLineCount === 0) {
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

        $this->lastLineCount = 0;
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
        if ($this->lastLineCount === 0) {
            return;
        }

        if ($this->lastLineCount > 1) {
            fwrite($this->stream, sprintf("\033[%dA", $this->lastLineCount - 1));
        }

        fwrite($this->stream, "\r\033[J");
    }

    private function measureWidth(): int
    {
        if (isset($_SERVER['COLUMNS']) && is_numeric($_SERVER['COLUMNS'])) {
            return (int) $_SERVER['COLUMNS'];
        }
        $cols = (int) shell_exec('tput cols 2>/dev/null');
        return $cols > 0 ? $cols : 80;
    }

    private function measureHeight(): int
    {
        if (isset($_SERVER['LINES']) && is_numeric($_SERVER['LINES'])) {
            return (int) $_SERVER['LINES'];
        }
        $lines = (int) shell_exec('tput lines 2>/dev/null');
        return $lines > 0 ? $lines : 24;
    }
}

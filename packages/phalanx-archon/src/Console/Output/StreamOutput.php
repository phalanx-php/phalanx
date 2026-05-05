<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Output;

use OpenSwoole\Process;
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
 * Non-TTY: update() is transient and clear() is a no-op. Permanent output
 * still goes through persist(), so pipes/CI get stable lines instead of every
 * spinner frame.
 */
final class StreamOutput
{
    private int $lastLineCount = 0;
    private bool $isTty;
    private bool $syncSupported;
    private int $cachedWidth;
    private int $cachedHeight;
    private TerminalEnvironment $terminal;

    /** @param resource $stream */
    public function __construct(private mixed $stream = STDOUT, ?TerminalEnvironment $terminal = null)
    {
        $this->terminal = $terminal ?? new TerminalEnvironment();
        $this->isTty = stream_isatty($this->stream);

        // Apple Terminal does not support CSI ?2026 (synchronized output) and the
        // sequences appear literally in scrollback history. Suppress them there.
        $this->syncSupported = $this->terminal->termProgram !== 'Apple_Terminal';

        // Compute terminal dimensions once at construction (acceptable blocking call
        // at boot before the event loop starts). SIGWINCH invalidates and recomputes
        // synchronously so width()/height() never block during rendering.
        $this->cachedWidth  = $this->measureWidth();
        $this->cachedHeight = $this->measureHeight();

        if ($this->isTty && extension_loaded('openswoole') && defined('SIGWINCH')) {
            // WeakReference breaks the $this capture cycle. If StreamOutput is ever
            // eligible for GC the signal handler becomes a no-op instead of pinning it.
            // Process::signal binds at the OpenSwoole reactor level — the handler only
            // fires while the reactor is processing events, which is exactly when the
            // prompt loop needs the dimensions to be current.
            $ref = WeakReference::create($this);
            Process::signal(SIGWINCH, static function () use ($ref): void {
                $self = $ref->get();
                if ($self === null) {
                    return;
                }
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
        if ($this->isTty && $this->syncSupported) {
            fwrite($this->stream, "\033[?2026h");
        }
        $this->eraseRegion();
        fwrite($this->stream, implode("\n", $lines));
        fwrite($this->stream, "\n");
        if ($this->isTty && $this->syncSupported) {
            fwrite($this->stream, "\033[?2026l");
        }

        $this->lastLineCount = 0;
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
        if ($this->terminal->columns !== null) {
            return $this->terminal->columns;
        }

        $cols = (int) shell_exec('tput cols 2>/dev/null');
        return $cols > 0 ? $cols : 80;
    }

    private function measureHeight(): int
    {
        if ($this->terminal->lines !== null) {
            return $this->terminal->lines;
        }

        $lines = (int) shell_exec('tput lines 2>/dev/null');
        return $lines > 0 ? $lines : 24;
    }
}

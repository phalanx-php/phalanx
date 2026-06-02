<?php

declare(strict_types=1);

namespace Phalanx\Skopos\Output;

/**
 * Color-prefixes per-process output drained from StreamingProcessHandle
 * incremental buffers and writes to a single output stream. Holds per-process
 * line buffers so partial chunks across drain ticks coalesce into whole lines
 * before being emitted with the process label.
 *
 * Buffers are stored on the multiplexer rather than captured by reference in
 * stream-event closures — long-running drain loops own the buffer state
 * explicitly so it survives across periodic ticks and gets flushed
 * deterministically when a process exits.
 */
final class Multiplexer
{
    /** @var array<string, string> per-process stdout line buffer */
    private array $stdoutBuffers = [];

    /** @var array<string, string> per-process stderr line buffer */
    private array $stderrBuffers = [];

    /** @param resource $output */
    public function __construct(
        private mixed $output = STDOUT,
        private(set) Palette $palette = new Palette(),
    ) {
    }

    public function writeLine(string $message): void
    {
        fwrite($this->output, $message . "\n");
    }

    public function writeOutput(string $name, string $chunk): void
    {
        $this->emit($name, $chunk, isStderr: false);
    }

    public function writeError(string $name, string $chunk): void
    {
        $this->emit($name, $chunk, isStderr: true);
    }

    /**
     * Flush any unterminated trailing bytes for a process. Call when the
     * process exits so the last partial line still reaches the output.
     */
    public function flush(string $name): void
    {
        $stdout = $this->stdoutBuffers[$name] ?? '';
        if ($stdout !== '') {
            $label = $this->palette->colorFor($name) . '[' . $name . ']' . $this->palette->reset() . ' ';
            fwrite($this->output, $label . $stdout . "\n");
            unset($this->stdoutBuffers[$name]);
        }

        $stderr = $this->stderrBuffers[$name] ?? '';
        if ($stderr !== '') {
            $label = $this->palette->stderrPrefix() . '[' . $name . ']' . $this->palette->reset() . ' ';
            fwrite($this->output, $label . $stderr . "\n");
            unset($this->stderrBuffers[$name]);
        }
    }

    private function emit(string $name, string $chunk, bool $isStderr): void
    {
        if ($chunk === '') {
            return;
        }

        $buffer = ($isStderr ? $this->stderrBuffers[$name] ?? '' : $this->stdoutBuffers[$name] ?? '') . $chunk;

        $color = $isStderr ? $this->palette->stderrPrefix() : $this->palette->colorFor($name);
        $label = $color . '[' . $name . ']' . $this->palette->reset() . ' ';

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            if ($line !== '') {
                fwrite($this->output, $label . $line . "\n");
            }
        }

        if ($isStderr) {
            $this->stderrBuffers[$name] = $buffer;
        } else {
            $this->stdoutBuffers[$name] = $buffer;
        }
    }
}

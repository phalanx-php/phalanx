<?php

declare(strict_types=1);

namespace Phalanx\Skopos\Output;

use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

final class Multiplexer
{
    private Palette $palette;
    private WritableStreamInterface $output;

    public function __construct(WritableStreamInterface $output, ?Palette $palette = null)
    {
        $this->output = $output;
        $this->palette = $palette ?? new Palette();
    }

    public function attach(string $name, ReadableStreamInterface $stdout, ReadableStreamInterface $stderr): void
    {
        $color = $this->palette->colorFor($name);
        $reset = $this->palette->reset();
        $stderrColor = $this->palette->stderrPrefix();

        self::attachStream($name, $stdout, $color, $reset, $this->output, false);
        self::attachStream($name, $stderr, $stderrColor, $reset, $this->output, true);
    }

    public function writeLine(string $message): void
    {
        $this->output->write($message . "\n");
    }

    private static function attachStream(
        string $name,
        ReadableStreamInterface $stream,
        string $color,
        string $reset,
        WritableStreamInterface $output,
        bool $isStderr,
    ): void {
        $buffer = '';
        $label = $color . '[' . $name . ']' . $reset . ' ';

        // Non-static: would need $buffer by ref. Static with explicit ref capture is correct here.
        // The buffer variable escapes into the closure as a reference — this is intentional and
        // bounded: the closure lives only as long as the stream, which is cleaned up on process exit.
        $stream->on('data', static function (string $chunk) use (&$buffer, $label, $output): void {
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if ($line !== '') {
                    $canWrite = $output->write($label . $line . "\n");

                    // WritableStreamInterface::write() returns false when the buffer is full.
                    // We intentionally do not back-pressure the child process here — dev output
                    // is low-volume and the consequence of dropping is worse than buffering.
                    // Production stream consumers must respect this return value.
                    unset($canWrite);
                }
            }
        });

        $stream->on('end', static function () use (&$buffer, $label, $output): void {
            if ($buffer !== '') {
                $output->write($label . $buffer . "\n");
                $buffer = '';
            }
        });

        $stream->on('error', static function (\Throwable $e) use ($name, $output, $isStderr): void {
            $src = $isStderr ? 'stderr' : 'stdout';
            $output->write("\033[31m[skopos] stream error on {$name} {$src}: {$e->getMessage()}\033[0m\n");
        });
    }
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Runtime;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Theatron\Terminal\Terminal;

fwrite(STDERR, sprintf(
    "[pre-boot] Hook flags: 0x%X | HOOK_STDIO=0x%X | stdio_hooked=%s\n",
    Runtime::getHookFlags(),
    Runtime::HOOK_STDIO,
    (Runtime::getHookFlags() & Runtime::HOOK_STDIO) ? 'YES' : 'no',
));

exit(Archon::command('raw-output-test', static function (CommandContext $ctx): int {

    fwrite(STDERR, sprintf(
        "[in-scope] Hook flags: 0x%X | stdio_hooked=%s\n",
        Runtime::getHookFlags(),
        (Runtime::getHookFlags() & Runtime::HOOK_STDIO) ? 'YES' : 'no',
    ));

    stream_set_write_buffer(STDOUT, 0);

    [$w, $h] = Terminal::size();

    fwrite(STDERR, sprintf("[in-scope] Terminal: %dx%d\n", $w, $h));

    fwrite(STDOUT, "\033[?1049h");
    fwrite(STDOUT, "\033[?25l");
    fwrite(STDOUT, "\033[2J");
    fflush(STDOUT);

    $render = static function (int $frame) use ($w, $h): void {
        $output = '';

        $output .= "\033[1;1H";
        $output .= "\033[38;2;0;255;255m";
        $output .= sprintf("Raw output test - frame %d", $frame);

        $output .= sprintf("\033[%d;1H", $h);
        $output .= "\033[48;2;48;48;48m\033[38;2;255;255;255m";

        $left = sprintf(" Counter: %d | Static: FIXED | Mode: raw", $frame);
        $right = sprintf("Frame: %d ", $frame);

        $padding = $w - strlen($left) - strlen($right);
        if ($padding < 0) {
            $padding = 0;
        }

        $bar = $left . str_repeat(' ', $padding) . $right;
        $output .= $bar;

        $output .= "\033[0m";

        $bytes = strlen($output);
        $written = @fwrite(STDOUT, $output);
        fflush(STDOUT);

        if ($frame <= 3 || $frame % 100 === 0) {
            fwrite(STDERR, sprintf(
                "[frame %d] output=%d bytes, fwrite=%s\n",
                $frame,
                $bytes,
                $written === false ? 'FAILED' : (string) $written,
            ));
        }
    };

    $render(0);

    $counter = 0;
    $ctx->periodic(1.0, static function () use (&$counter): void {
        $counter++;
    });

    $ctx->periodic(0.05, static function () use (&$counter, $render): void {
        static $frame = 0;
        $frame++;
        $render($counter);
    });

    while (!$ctx->isCancelled) {
        $ctx->delay(0.1);
    }

    fwrite(STDOUT, "\033[0m\033[?25h\033[?1049l");
    fflush(STDOUT);

    return 0;
})->default('raw-output-test')->run(array_slice($_SERVER['argv'] ?? [], 1)));

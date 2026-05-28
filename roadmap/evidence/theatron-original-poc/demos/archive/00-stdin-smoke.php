#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenSwoole\Process;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\MouseEvent;
use Phalanx\Theatron\Input\PasteEvent;
use Phalanx\Theatron\Input\ResizeEvent;
use Phalanx\Theatron\Terminal\Terminal;

exit(Archon::command('stdin-smoke', static function (CommandContext $ctx): int {
    $consoleInput = $ctx->service(ConsoleInput::class);
    $parser = new EventParser();
    $terminal = Terminal::detect(getenv() ?: []);

    $initMem = memLabel();
    fwrite(STDOUT, "\033[2J\033[H");
    fwrite(STDOUT, "=== Theatron STDIN Smoke Test ===\r\n");
    fwrite(STDOUT, "Terminal: {$terminal->width}x{$terminal->height} | Boot memory: {$initMem}\r\n");
    fwrite(STDOUT, "Press keys, click mouse, resize window. Ctrl+C to exit.\r\n");
    fwrite(STDOUT, str_repeat('-', 60) . "\r\n");

    if (!$consoleInput->isInteractive) {
        fwrite(STDOUT, "Not a TTY — raw mode disabled, reading piped input only.\r\n");
    } else {
        $consoleInput->enableRawMode($ctx);
        fwrite(STDOUT, "\033[?2004h");
        fwrite(STDOUT, "\033[?1003h");
        fwrite(STDOUT, "\033[?1006h");
    }

    $ctx->onDispose(static function () use ($consoleInput, $ctx): void {
        if ($consoleInput->isInteractive) {
            $consoleInput->restore($ctx);
            fwrite(STDOUT, "\r\n\033[?1006l\033[?1003l\033[?2004l");
        }
        fwrite(STDOUT, "\r\nTerminal restored. Exiting.\r\n");
    });

    $eventCount = 0;
    $startTime = hrtime(true);

    Process::signal(SIGWINCH, static function () use (&$eventCount, $startTime): void {
        [$cols, $rows] = Terminal::size();
        $eventCount++;
        $elapsed = (hrtime(true) - $startTime) / 1_000_000;
        $mem = memLabel();
        fwrite(STDOUT, sprintf(
            "#%04d [%8.1fms] [%s] RESIZE         %dx%d\r\n",
            $eventCount,
            $elapsed,
            $mem,
            $cols,
            $rows,
        ));
    });

    $ctx->go(static function () use ($ctx, $consoleInput, $parser, &$eventCount, $startTime): void {
        $emptyReads = 0;
        while (!$ctx->isCancelled) {
            $readStart = hrtime(true);
            $bytes = $consoleInput->read($ctx, 128, 0.5);

            if ($bytes === '') {
                $emptyReads++;
                if (!$consoleInput->isInteractive && $emptyReads > 3) {
                    fwrite(STDOUT, "EOF reached on piped input.\r\n");
                    return;
                }
                continue;
            }
            $emptyReads = 0;

            $readLatency = (hrtime(true) - $readStart) / 1_000_000;
            $events = $parser->parse($bytes);

            foreach ($events as $event) {
                $eventCount++;
                $elapsed = (hrtime(true) - $startTime) / 1_000_000;
                $line = formatEvent($event, $eventCount, $elapsed, $readLatency);
                fwrite(STDOUT, $line . "\r\n");
            }

            if ($events === []) {
                $eventCount++;
                $elapsed = (hrtime(true) - $startTime) / 1_000_000;
                $hex = bin2hex($bytes);
                $mem = memLabel();
                fwrite(STDOUT, sprintf(
                    "#%04d [%8.1fms] [%s] RAW            bytes=%d hex=%s latency=%.2fms\r\n",
                    $eventCount,
                    $elapsed,
                    $mem,
                    strlen($bytes),
                    $hex,
                    $readLatency,
                ));
            }
        }
    }, 'stdin-reader');

    while (!$ctx->isCancelled) {
        $ctx->delay(0.1);
    }

    return 0;
})->default('stdin-smoke')->run(array_slice($_SERVER['argv'] ?? [], 1)));

function memLabel(): string
{
    $bytes = memory_get_usage();
    return match (true) {
        $bytes >= 1_048_576 => sprintf('%.1fMB', $bytes / 1_048_576),
        $bytes >= 1_024 => sprintf('%.0fKB', $bytes / 1_024),
        default => "{$bytes}B",
    };
}

function formatEvent(InputEvent $event, int $count, float $elapsed, float $latency): string
{
    $mem = memLabel();
    $prefix = sprintf("#%04d [%8.1fms] [%s]", $count, $elapsed, $mem);

    if ($event instanceof KeyEvent) {
        $key = $event->key instanceof \Phalanx\Theatron\Input\Key
            ? $event->key->value
            : ($event->isChar() ? "'{$event->key}'" : bin2hex($event->key));

        $mods = implode('+', array_filter([
            $event->ctrl ? 'Ctrl' : null,
            $event->alt ? 'Alt' : null,
            $event->shift ? 'Shift' : null,
        ]));

        return sprintf(
            "%s KEY   %-12s %-15s latency=%.2fms",
            $prefix,
            $mods !== '' ? "[{$mods}]" : '',
            $key,
            $latency,
        );
    }

    if ($event instanceof MouseEvent) {
        return sprintf(
            "%s MOUSE %-8s %-8s at (%d,%d) %s latency=%.2fms",
            $prefix,
            $event->button->name,
            $event->action->name,
            $event->x,
            $event->y,
            implode('+', array_filter([
                $event->ctrl ? 'Ctrl' : null,
                $event->alt ? 'Alt' : null,
                $event->shift ? 'Shift' : null,
            ])),
            $latency,
        );
    }

    if ($event instanceof PasteEvent) {
        $preview = mb_strlen($event->content) > 40
            ? mb_substr($event->content, 0, 40) . '...'
            : $event->content;
        return sprintf(
            "%s PASTE len=%d preview=\"%s\" latency=%.2fms",
            $prefix,
            mb_strlen($event->content),
            str_replace(["\r", "\n"], ["\\r", "\\n"], $preview),
            $latency,
        );
    }

    if ($event instanceof ResizeEvent) {
        return sprintf(
            "%s RESIZE %dx%d latency=%.2fms",
            $prefix,
            $event->width,
            $event->height,
            $latency,
        );
    }

    return sprintf("%s UNKNOWN %s", $prefix, $event::class);
}

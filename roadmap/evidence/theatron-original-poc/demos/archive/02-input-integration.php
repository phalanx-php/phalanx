#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\MouseButton;
use Phalanx\Theatron\Input\MouseEvent;
use Phalanx\Theatron\Input\PasteEvent;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

exit(Archon::command('input-demo', static function (CommandContext $ctx): int {
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        mouseTracking: true,
        handleInput: true,
        activeIntervalUs: 16_667,
    ));

    $w = $stage->width();
    $h = $stage->height();

    $messages = [
        'Theatron Input Integration Demo',
        '',
        'Type a message and press Enter to add it here.',
        'Mouse wheel scrolls. PgUp/PgDn moves by page.',
        'Ctrl+C or Escape exits.',
        '',
    ];
    $inputBuffer = '';
    $scrollOffset = 0;
    $messageCount = 0;
    $frames = 0;
    $startTime = microtime(true);

    $chatRegion = $stage->region('chat', Rect::of(0, 0, $w, $h - 2));
    $inputRegion = $stage->region('input', Rect::of(0, $h - 2, $w, 1));
    $barRegion = $stage->region('bar', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($chatRegion, $inputRegion, $barRegion): void {
        $chatRegion->resize(Rect::of(0, 0, $nw, $nh - 2));
        $inputRegion->resize(Rect::of(0, $nh - 2, $nw, 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $stage->onInput(static function (InputEvent $event) use (
        &$messages, &$inputBuffer, &$scrollOffset, &$messageCount,
    ): void {
        if ($event instanceof KeyEvent) {
            if ($event->is(Key::PageUp)) {
                $scrollOffset = min($scrollOffset + 10, max(0, count($messages) - 5));
                return;
            }

            if ($event->is(Key::PageDown)) {
                $scrollOffset = max(0, $scrollOffset - 10);
                return;
            }

            if ($event->is(Key::Enter)) {
                if ($inputBuffer !== '') {
                    $messageCount++;
                    $timestamp = date('H:i:s');
                    $messages[] = sprintf('[%s] #%d: %s', $timestamp, $messageCount, $inputBuffer);
                    $inputBuffer = '';
                    $scrollOffset = 0;
                }
                return;
            }

            if ($event->is(Key::Backspace)) {
                $inputBuffer = mb_substr($inputBuffer, 0, -1);
                return;
            }

            $char = $event->char();
            if ($char !== null) {
                $inputBuffer .= $char;
            }

            return;
        }

        if ($event instanceof MouseEvent) {
            if ($event->button === MouseButton::ScrollUp) {
                $scrollOffset = min($scrollOffset + 3, max(0, count($messages) - 5));
                return;
            }

            if ($event->button === MouseButton::ScrollDown) {
                $scrollOffset = max(0, $scrollOffset - 3);
                return;
            }

            return;
        }

        if ($event instanceof PasteEvent) {
            $inputBuffer .= $event->content;
        }
    });

    $drawFrame = static function () use (
        $ui, $chatRegion, $inputRegion, $barRegion, $stage,
        &$messages, &$inputBuffer, &$scrollOffset,
        &$messageCount, &$frames, $startTime,
    ): void {
        $frames++;

        $elapsed = microtime(true) - $startTime;
        $fps = $elapsed > 0.5 ? $frames / $elapsed : 0;
        $mem = memory_get_usage();
        $memLabel = $mem >= 1_048_576
            ? sprintf('%.1fMB', $mem / 1_048_576)
            : sprintf('%.0fKB', $mem / 1_024);

        $visibleHeight = max(1, $stage->height() - 4);
        $total = count($messages);
        $start = max(0, $total - $visibleHeight - $scrollOffset);
        $displayLines = array_slice($messages, $start, $visibleHeight);
        $following = $scrollOffset === 0;

        $chatRegion->draw($ui->panel(
            'Messages',
            $ui->scrollable(implode("\n", $displayLines), style: Style::of(color: Color::white())),
            style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
        ));

        $inputRegion->draw($ui->input(
            value: $inputBuffer,
            prompt: '> ',
            cursor: mb_strlen($inputBuffer),
            style: Style::of(color: Color::white(), background: Color::indexed(236)),
        ));

        $scrollIndicator = $following ? 'TAIL' : 'SCROLL';
        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Msgs: %d | Lines: %d | %s', $messageCount, $total, $scrollIndicator),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    sprintf('FPS: %.0f | Mem: %s | %.1fs ', $fps, $memLabel, $elapsed),
                    style: Style::of(color: Color::brightWhite(), background: Color::indexed(236)),
                ),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));
    };

    $drawFrame();
    $ctx->periodic(0.05, $drawFrame);
    $stage->run($ctx);

    return 0;
})->default('input-demo')->run(array_slice($_SERVER['argv'] ?? [], 1)));

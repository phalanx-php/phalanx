#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class LiveComposer implements StatefulComponent, InputTarget
{
    private ?Signal $text = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->text = $ctx->signal('');
        $ui = $ctx->ui;

        return $ui->column(
            $ui->text('TH-C2 INPUT -> SIGNAL -> FRAME', Style::of(size: Size::fixed(1), color: Color::brightCyan())),
            $ui->text('', Style::of(size: Size::fixed(1))),
            $ui->text('Type into the focused composer. Esc exits.', Style::of(size: Size::fixed(1), color: Color::indexed(244))),
            $ui->text('Working: keys update via signal; Stage renders the frame.', Style::of(size: Size::fixed(1), color: Color::indexed(244))),
            $ui->text('', Style::of(size: Size::fixed(1))),
            $ui->text('composer> ' . $this->text->value, Style::of(size: Size::fixed(1), color: Color::brightWhite())),
        );
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent || !$this->text instanceof Signal) {
            return false;
        }

        if ($event->is(Key::Backspace)) {
            $this->text->value = mb_substr($this->text->value, 0, -1);

            return true;
        }

        if ($event->is(Key::Space)) {
            $this->text->value .= ' ';

            return true;
        }

        $char = $event->char();
        if ($char === null) {
            return false;
        }

        $this->text->value .= $char;

        return true;
    }
}

exit(Archon::command('input-signal-composer', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-06-input-signal.bin' : null,
    ));

    $component = new LiveComposer();
    $mount = new MountedComponent($component);
    $focus = new FocusManager();
    $parser = new EventParser();
    $needsDraw = true;

    $mount->render();
    $focus->register('composer', $mount);
    $focus->focus('composer');

    if ($maxFrames !== null) {
        foreach ($parser->parse('status ready') as $event) {
            $focus->dispatch($event);
        }
    }

    $w = $stage->width();
    $h = $stage->height();
    $panelRegion = $stage->region('composer', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($panelRegion, $barRegion): void {
        $panelRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $stage->onInput(static function (InputEvent $event) use ($focus, $mount, $stage): void {
        if (!$focus->dispatch($event)) {
            return;
        }

        if ($mount->isDirty) {
            $stage->requestFrame();
        }
    });

    $frames = 0;

    $stage->onDraw(static function () use (
        $ui,
        $mount,
        $barRegion,
        $panelRegion,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;

        if ($needsDraw || $mount->consumeDirty()) {
            $panelRegion->draw($ui->panel(
                'Input Signal Composer',
                $mount->render(),
                style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
            ));
            $needsDraw = false;
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Focus: composer | Frames: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-C2 ',
                    style: Style::of(color: Color::brightWhite(), background: Color::indexed(236)),
                ),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));
    });

    if ($maxFrames !== null) {
        $stage->start($ctx);

        while ($frames < $maxFrames) {
            $ctx->delay(0.01);
        }

        $stage->stop();

        return 0;
    }

    $stage->run($ctx);

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('input-signal-composer')->run(array_slice($_SERVER['argv'] ?? [], 1)));

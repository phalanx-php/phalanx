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

final class ShowcaseApp implements StatefulComponent, InputTarget
{
    private ?Signal $inputText = null;
    private ?Signal $progress = null;
    private ?Signal $logContent = null;
    private ?Signal $spinnerFrame = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->inputText = $ctx->signal('');
        $this->progress = $ctx->signal(0.0);
        $this->logContent = $ctx->signal("Theatron TDOM-2 showcase started\nAll 11 element types active");
        $this->spinnerFrame = $ctx->signal(0);

        $header = $ctx->ui->row(
            $ctx->ui->text('THEATRON TDOM-2 SHOWCASE', Style::of(size: Size::fill(), color: Color::brightCyan())),
            $ctx->ui->spinner(label: null, frame: $this->spinnerFrame->value, style: Style::of(size: Size::fixed(1), color: Color::brightGreen())),
        );

        $gridCells = $ctx->ui->grid(
            [Size::fill(), Size::fill()],
            $ctx->ui->text('Leonidas', Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text('Pericles', Style::of(size: Size::fixed(1), color: Color::brightYellow())),
            $ctx->ui->text('Achilles', Style::of(size: Size::fixed(1), color: Color::brightRed())),
            $ctx->ui->text('Odysseus', Style::of(size: Size::fixed(1), color: Color::brightBlue())),
        );

        $leftPanel = $ctx->ui->panel(
            'Elements',
            $ctx->ui->column(
                $gridCells,
                $ctx->ui->divider(Style::of(size: Size::fixed(1), color: Color::indexed(240))),
                $ctx->ui->progress($this->progress->value, label: 'Marathon', style: Style::of(size: Size::fixed(1))),
                $ctx->ui->text('', Style::of(size: Size::fixed(1))),
                $ctx->ui->input(value: $this->inputText->value, prompt: 'agora> ', cursor: mb_strlen($this->inputText->value), style: Style::of(size: Size::fixed(1))),
            ),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightCyan()),
        );

        $rightPanel = $ctx->ui->panel(
            'Oracle',
            $ctx->ui->scrollable($this->logContent->value, maxLines: 12),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightGreen()),
        );

        return $ctx->ui->column(
            $header,
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->row($leftPanel, $rightPanel),
        );
    }

    public function tickState(int $frame): void
    {
        if ($this->spinnerFrame !== null) {
            $this->spinnerFrame->value = $frame;
        }

        if ($this->progress !== null && $this->progress->value < 1.0) {
            $this->progress->value = min(1.0, $this->progress->value + 0.02);
        }

        if ($this->logContent !== null && $frame % 10 === 0 && $frame > 0) {
            $this->logContent->value .= sprintf("\nFrame %d — phalanx holds", $frame);
        }
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent || $this->inputText === null) {
            return false;
        }

        if ($event->is(Key::Backspace)) {
            $this->inputText->value = mb_substr($this->inputText->value, 0, -1);

            return true;
        }

        if ($event->is(Key::Space)) {
            $this->inputText->value .= ' ';

            return true;
        }

        $char = $event->char();

        if ($char === null) {
            return false;
        }

        $this->inputText->value .= $char;

        return true;
    }
}

exit(Archon::command('tdom-showcase', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-09-tdom-showcase.bin' : null,
    ));

    $app = new ShowcaseApp();
    $mount = new MountedComponent($app);
    $focus = new FocusManager();
    $parser = new EventParser();
    $needsDraw = true;

    $mount->render();
    $focus->register('showcase', $mount);
    $focus->focus('showcase');

    if ($maxFrames !== null) {
        foreach ($parser->parse('deploy') as $event) {
            $focus->dispatch($event);
        }
    }

    $w = $stage->width();
    $h = $stage->height();
    $mainRegion = $stage->region('main', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($mainRegion, $barRegion): void {
        $mainRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
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
        $app,
        $mount,
        $mainRegion,
        $barRegion,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;
        $app->tickState($frames);

        if ($needsDraw || $mount->consumeDirty()) {
            $mainRegion->draw($mount->render());
            $needsDraw = false;
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Mode: showcase | Elements: 11 | Frame: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-TDOM-2 ',
                    style: Style::of(color: Color::brightCyan(), background: Color::indexed(236)),
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
]))->default('tdom-showcase')->run(array_slice($_SERVER['argv'] ?? [], 1)));

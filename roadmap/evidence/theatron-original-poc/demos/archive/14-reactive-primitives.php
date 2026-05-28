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
use Phalanx\Theatron\Reactive\Computed;
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

final class GarrisonDashboard implements StatefulComponent, InputTarget
{
    private ?Signal $active = null;
    private ?Signal $reserves = null;
    private ?Signal $injured = null;
    private ?Signal $eventLog = null;
    private ?Computed $totalStrength = null;
    private ?Computed $readiness = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $active = $ctx->signal(300, key: 'active');
        $reserves = $ctx->signal(150, key: 'reserves');
        $this->injured = $ctx->signal(20, key: 'injured');
        $this->eventLog = $ctx->signal("Garrison Sparta initialized\n300 hoplites standing ready", key: 'log');

        $this->totalStrength = $ctx->computed(static function () use ($active, $reserves): int {
            return $active->value + $reserves->value;
        }, key: 'strength');

        $totalStrength = $this->totalStrength;

        $this->readiness = $ctx->computed(static function () use ($active, $totalStrength): string {
            if ($totalStrength->value === 0) {
                return '0%';
            }

            return round(($active->value / $totalStrength->value) * 100) . '%';
        }, key: 'readiness');

        $eventLog = $this->eventLog;

        $ctx->watch(
            static function () use ($active): int {
                return $active->value;
            },
            static function (mixed $new, mixed $old) use ($eventLog): void {
                $delta = $new - $old;
                $verb = $delta > 0 ? 'reinforced' : 'casualties';
                $eventLog->value .= sprintf("\nHoplites %s: %+d (now %d)", $verb, $delta, $new);
            },
        );

        $ctx->watch(
            static function () use ($reserves): int {
                return $reserves->value;
            },
            static function (mixed $new, mixed $old) use ($eventLog): void {
                $delta = $new - $old;
                $verb = $delta > 0 ? 'mustered' : 'deployed';
                $eventLog->value .= sprintf("\nReserves %s: %+d (now %d)", $verb, $delta, $new);
            },
        );

        $ui = $ctx->ui;

        $header = $ui->row(
            $ui->text('GARRISON SPARTA', Style::of(size: Size::fill(), color: Color::brightCyan())),
            $ui->text(
                sprintf(' Readiness: %s ', $this->readiness->value),
                Style::of(color: Color::brightGreen()),
            ),
        );

        $statsPanel = $ui->panel(
            'Forces',
            $ui->column(
                $ui->text(
                    sprintf('Active Hoplites:  %d', $this->active->value),
                    Style::of(size: Size::fixed(1), color: Color::brightWhite()),
                ),
                $ui->text(
                    sprintf('Reserve Forces:   %d', $this->reserves->value),
                    Style::of(size: Size::fixed(1), color: Color::brightYellow()),
                ),
                $ui->text(
                    sprintf('Injured:          %d', $this->injured->value),
                    Style::of(size: Size::fixed(1), color: Color::brightRed()),
                ),
                $ui->divider(Style::of(size: Size::fixed(1), color: Color::indexed(240))),
                $ui->text(
                    sprintf('Total Strength:   %d', $this->totalStrength->value),
                    Style::of(size: Size::fixed(1), color: Color::brightCyan()),
                ),
            ),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightCyan()),
        );

        $logPanel = $ui->panel(
            'Battle Chronicle',
            $ui->scrollable($this->eventLog->value, maxLines: 12),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightGreen()),
        );

        $controls = $ui->text(
            ' [Up/Down] Active +/-10  [Left/Right] Reserves +/-10  [q] Quit ',
            Style::of(size: Size::fixed(1), color: Color::indexed(240)),
        );

        return $ui->column(
            $header,
            $ui->text('', Style::of(size: Size::fixed(1))),
            $ui->row($statsPanel, $logPanel),
            $controls,
        );
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        if ($event->is(Key::Up) && $this->active !== null) {
            $this->active->value = min(1000, $this->active->value + 10);

            return true;
        }

        if ($event->is(Key::Down) && $this->active !== null) {
            $this->active->value = max(0, $this->active->value - 10);

            return true;
        }

        if ($event->is(Key::Right) && $this->reserves !== null) {
            $this->reserves->value = min(500, $this->reserves->value + 10);

            return true;
        }

        if ($event->is(Key::Left) && $this->reserves !== null) {
            $this->reserves->value = max(0, $this->reserves->value - 10);

            return true;
        }

        return false;
    }
}

exit(Archon::command('garrison', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-14-reactive-primitives.bin' : null,
    ));

    $app = new GarrisonDashboard();
    $mount = new MountedComponent($app);
    $focus = new FocusManager();
    $parser = new EventParser();
    $needsDraw = true;

    $mount->render();
    $focus->register('garrison', $mount);
    $focus->focus('garrison');

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
        $mount,
        $mainRegion,
        $barRegion,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;

        if ($needsDraw || $mount->consumeDirty()) {
            $mainRegion->draw($mount->render());
            $needsDraw = false;
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Garrison Sparta | Frame: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-B.03 ',
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
]))->default('garrison')->run(array_slice($_SERVER['argv'] ?? [], 1)));

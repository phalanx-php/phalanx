#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\WaitKind;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\DevTools\AegisRuntimeStoreProjector;
use Phalanx\Theatron\DevTools\RuntimeMetricsSlice;
use Phalanx\Theatron\DevTools\RuntimeScopeSlice;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class CommandComposer implements StatefulComponent, InputTarget
{
    private ?Signal $command = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->command = $ctx->signal('');

        return $ctx->ui->column(
            $ctx->ui->text('THEATRON CONSOLE DX', Style::of(size: Size::fixed(1), color: Color::brightCyan())),
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->text('command> ' . $this->command->value, Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->text('Type here: the command text is signal-backed focused input.', Style::of(size: Size::fixed(1), color: Color::indexed(244))),
        );
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent || !$this->command instanceof Signal) {
            return false;
        }

        if ($event->is(Key::Backspace)) {
            $this->command->value = mb_substr($this->command->value, 0, -1);

            return true;
        }

        if ($event->is(Key::Space)) {
            $this->command->value .= ' ';

            return true;
        }

        $char = $event->char();
        if ($char === null) {
            return false;
        }

        $this->command->value .= $char;

        return true;
    }
}

final class RuntimeSurface implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $metrics = $ctx->lens(RuntimeMetricsSlice::class)->value;
        $scope = $ctx->lens(RuntimeScopeSlice::class)->value;

        return $ctx->ui->column(
            $ctx->ui->text(sprintf('runtime     %s %s', $ctx->currentRunId === '' ? 'idle' : $ctx->currentRunId, $ctx->currentRunState === '' ? 'none' : $ctx->currentRunState), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('scopes      %d', $ctx->activeScopes), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('frames      %d', $metrics->frames), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->text('Aegis runtime projected through Store lens.', Style::of(size: Size::fixed(1), color: Color::indexed(244))),
        );
    }
}

exit(Archon::command('theatron-console-dx', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');

    $memory = RuntimeMemory::forLedgerSize(64);
    $runtime = new RuntimeContext($memory);
    $scopeResource = $memory->resources->open(AegisResourceSid::Scope, id: 'scope-1', state: ManagedResourceState::Active);
    $task = $memory->resources->open(
        type: AegisResourceSid::TaskRun,
        id: 'task-1',
        parentResourceId: $scopeResource->id,
        ownerScopeId: $scopeResource->id,
        state: ManagedResourceState::Active,
    );

    $memory->resources->annotate($task, AegisAnnotationSid::RunName, 'ConsoleDX');
    $memory->resources->annotate($task, AegisAnnotationSid::RunState, RunState::Suspended->value);
    $memory->resources->annotate($task, AegisAnnotationSid::WaitKind, WaitKind::Input->value);
    $memory->resources->annotate($task, AegisAnnotationSid::WaitDetail, 'composer');
    $memory->counters->incr('theatron.frames', 1);

    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'console-dx',
        RuntimeMetricsSlice::class,
        RuntimeScopeSlice::class,
    ));
    $registry->start($ctx);

    $projector = new AegisRuntimeStoreProjector($runtime, $registry->writer());
    $lens = $registry->lens();

    $composer = new MountedComponent(new CommandComposer());
    $runtimeSurface = new MountedComponent(new RuntimeSurface(), $ctx, $lens);

    $focus = new FocusManager();
    $parser = new EventParser();
    $needsDraw = true;

    $composer->render();
    $focus->register('composer', $composer);
    $focus->focus('composer');

    if ($maxFrames !== null) {
        foreach ($parser->parse('deploy') as $event) {
            $focus->dispatch($event);
        }
    }

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-08-console-dx.bin' : null,
    ));

    $ui = new Ui();
    $w = $stage->width();
    $h = $stage->height();
    $commandRegion = $stage->region('command', Rect::of(0, 0, $w, max(8, intdiv($h, 2))));
    $runtimeRegion = $stage->region('runtime', Rect::of(0, max(8, intdiv($h, 2)), $w, max(4, $h - max(8, intdiv($h, 2)) - 1)));
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($commandRegion, $runtimeRegion, $barRegion): void {
        $split = max(8, intdiv($nh, 2));
        $commandRegion->resize(Rect::of(0, 0, $nw, $split));
        $runtimeRegion->resize(Rect::of(0, $split, $nw, max(4, $nh - $split - 1)));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $stage->onInput(static function (InputEvent $event) use ($focus, $composer, $stage): void {
        if (!$focus->dispatch($event)) {
            return;
        }

        if ($composer->isDirty) {
            $stage->requestFrame();
        }
    });

    $frames = 0;

    $stage->onDraw(static function () use (
        $ui,
        $ctx,
        $memory,
        $projector,
        $composer,
        $runtimeSurface,
        $barRegion,
        $commandRegion,
        $runtimeRegion,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;
        $memory->counters->incr('theatron.frames', 1);

        $projector->project($ctx, $frames);

        if ($needsDraw || $composer->consumeDirty()) {
            $commandRegion->draw($ui->panel(
                'Composer',
                $composer->render(),
                style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
            ));
            $needsDraw = false;
        }

        if ($runtimeSurface->consumeDirty()) {
            $runtimeRegion->draw($ui->panel(
                'Runtime',
                $runtimeSurface->render(),
                style: Style::of(border: Border::Rounded, color: Color::brightGreen()),
            ));
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Focus: composer | Frames: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-C2 + TH-A2 ',
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
        $composer->dispose();
        $runtimeSurface->dispose();
        $memory->shutdown();

        return 0;
    }

    $stage->run($ctx);
    $composer->dispose();
    $runtimeSurface->dispose();
    $memory->shutdown();

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('theatron-console-dx')->run(array_slice($_SERVER['argv'] ?? [], 1)));

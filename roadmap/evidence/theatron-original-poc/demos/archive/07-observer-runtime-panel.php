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

final class RuntimePanel implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $metrics = $ctx->lens(RuntimeMetricsSlice::class)->value;
        $scope = $ctx->lens(RuntimeScopeSlice::class)->value;

        return $ctx->ui->column(
            $ctx->ui->text('RUNTIME PROJECTION VIA STORE', Style::of(size: Size::fixed(1), color: Color::brightGreen())),
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->text('Live values from Aegis RuntimeMemory:', Style::of(size: Size::fixed(1), color: Color::indexed(244))),
            $ctx->ui->text('', Style::of(size: Size::fixed(1))),
            $ctx->ui->text(sprintf('Run          %s', $ctx->currentRunId === '' ? 'idle' : $ctx->currentRunId), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('State        %s', $ctx->currentRunState === '' ? 'none' : $ctx->currentRunState), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('Scopes       %d', $ctx->activeScopes), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('Frames       %d', $metrics->frames), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('Tasks        %d', $metrics->tasks), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf('Handles      %d', $metrics->handles), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
        );
    }
}

exit(Archon::command('runtime-panel', static function (CommandContext $ctx): int {
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

    $memory->resources->annotate($task, AegisAnnotationSid::RunName, 'RenderStatus');
    $memory->resources->annotate($task, AegisAnnotationSid::RunState, RunState::Suspended->value);
    $memory->resources->annotate($task, AegisAnnotationSid::WaitKind, WaitKind::Input->value);
    $memory->resources->annotate($task, AegisAnnotationSid::WaitDetail, 'stdin');
    $memory->counters->incr('theatron.frames', 1);

    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'runtime-panel',
        RuntimeMetricsSlice::class,
        RuntimeScopeSlice::class,
    ));
    $registry->start($ctx);

    $projector = new AegisRuntimeStoreProjector($runtime, $registry->writer());
    $mount = new MountedComponent(new RuntimePanel(), $ctx, $registry->lens());

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 100_000,
        captureFile: $capture ? '/tmp/theatron-07-runtime-panel.bin' : null,
    ));

    $ui = new Ui();
    $w = $stage->width();
    $h = $stage->height();
    $panelRegion = $stage->region('panel', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($panelRegion, $barRegion): void {
        $panelRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $frames = 0;
    $needsDraw = true;

    $stage->onDraw(static function () use (
        $ui,
        $ctx,
        $memory,
        $task,
        $projector,
        $mount,
        $barRegion,
        $panelRegion,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;
        $memory->counters->incr('theatron.frames', 1);

        if ($frames === 8) {
            $memory->resources->annotate($task, AegisAnnotationSid::RunState, RunState::Running->value);
            $memory->resources->annotate($task, AegisAnnotationSid::WaitKind, WaitKind::Channel->value);
            $memory->resources->annotate($task, AegisAnnotationSid::WaitDetail, 'render-events');
        }

        $projector->project($ctx, $frames);

        if ($needsDraw || $mount->consumeDirty()) {
            $panelRegion->draw($ui->panel(
                'Aegis Runtime',
                $mount->render(),
                style: Style::of(border: Border::Rounded, color: Color::brightGreen()),
            ));
            $needsDraw = false;
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Aegis runtime via Store lens | Frames: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-A.02 ',
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
        $mount->dispose();
        $memory->shutdown();

        return 0;
    }

    $stage->run($ctx);
    $mount->dispose();
    $memory->shutdown();

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('runtime-panel')->run(array_slice($_SERVER['argv'] ?? [], 1)));

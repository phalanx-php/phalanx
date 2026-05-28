#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\TabbedLayout;
use Phalanx\Theatron\Component\TabbedLayoutConfig;
use Phalanx\Theatron\Demos\Capstone\Component\AgentStatusPanel;
use Phalanx\Theatron\Demos\Capstone\Component\ConversationPanel;
use Phalanx\Theatron\Demos\Capstone\Component\HumanInputPanel;
use Phalanx\Theatron\Demos\Capstone\Component\TaskBoardPanel;
use Phalanx\Theatron\Demos\Capstone\Reactor\SimulationReactor;
use Phalanx\Theatron\Demos\Capstone\Reactor\SwarmEventRouter;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentInfo;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Demos\Capstone\Slice\ConversationSlice;
use Phalanx\Theatron\Demos\Capstone\Slice\TaskBoardSlice;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Stream\TheatronStream;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

exit(Archon::command('capstone', static function (CommandContext $ctx): int {
    $dirty = new DirtyBatch();
    $stream = new TheatronStream($dirty);
    $ui = new Ui();
    $stage = Stage::boot(new StageConfig(
        activeIntervalUs: 50_000,
        handleInput: true,
        defaultExitHandler: false,
    ));

    $registry = StoreRegistry::fromDefinitions(
        Store::memory(
            'capstone',
            AgentRegistrySlice::class,
            ConversationSlice::class,
            TaskBoardSlice::class,
        ),
    );
    $registry->start($ctx);

    $writer = $registry->writer();
    $lens = $registry->lens();

    $writer->set(new AgentRegistrySlice([
        'researcher' => new AgentInfo(id: 'researcher', name: 'Archimedes', role: 'research'),
        'analyst' => new AgentInfo(id: 'analyst', name: 'Thales', role: 'analysis'),
        'steward' => new AgentInfo(id: 'steward', name: 'Pericles', role: 'coordination'),
    ]));

    $agents = new AgentStatusPanel(lens: $lens);
    $conversation = new ConversationPanel(lens: $lens);
    $tasks = new TaskBoardPanel(lens: $lens);
    $input = new HumanInputPanel(stream: $stream);

    $layout = new TabbedLayout(TabbedLayoutConfig::horizontal());
    $layout->add('Agents', $agents);
    $layout->add('Messages', $conversation);
    $layout->add('Tasks', $tasks);
    $layout->add('Command', $input);

    $reactorContext = new ReactorContext(
        scope: $ctx,
        lens: $lens,
        writer: $writer,
        dirty: $dirty,
    );

    $eventRouter = new SwarmEventRouter();
    $eventRouter->subscribe($stream, $reactorContext);

    $simulation = new SimulationReactor();

    $stream->start($ctx);
    $simulation->start($ctx, $stream);

    $needsDraw = true;

    $layout->focus->onFocusChanged(static function () use (&$needsDraw): void {
        $needsDraw = true;
    });

    $stage->onInput(static function (InputEvent $event) use ($layout, $stage, &$needsDraw): void {
        if (!$event instanceof KeyEvent) {
            return;
        }

        if ($layout->isQuit($event)) {
            $stage->stop();

            return;
        }

        if ($layout->handleInput($event)) {
            $needsDraw = true;
        }
    });

    $w = $stage->width();
    $h = $stage->height();
    $mainRegion = $stage->region('main', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($mainRegion, $barRegion, &$needsDraw): void {
        $mainRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
        $needsDraw = true;
    });

    $stage->onDraw(static function () use (
        $ui,
        $layout,
        $dirty,
        $agents,
        $conversation,
        $tasks,
        $input,
        $mainRegion,
        $barRegion,
        &$needsDraw,
    ): void {
        $streamDirty = $dirty->consume();

        if (!$needsDraw && !$streamDirty) {
            return;
        }

        $activeName = $layout->activeName();

        $body = $ui->column(
            $layout->renderNavBar($ui),
            $ui->row(
                $agents->render($ui, $activeName === 'Agents'),
                $conversation->render($ui, $activeName === 'Messages'),
                $tasks->render($ui, $activeName === 'Tasks'),
            ),
            $input->render($ui, $activeName === 'Command'),
        );

        $mainRegion->draw($body);

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(Line::from(
                    Span::styled(
                        ' PHALANX CAPSTONE ',
                        TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold(),
                    ),
                    Span::styled(
                        " {$activeName} ",
                        TextStyle::new()->fg(Color::brightWhite())->bg(Color::indexed(236)),
                    ),
                    Span::styled(
                        '  Tab:nav   h/l:panel   j/k:scroll   i:insert   Esc:normal   q:quit  ',
                        TextStyle::new()->fg(Color::indexed(242))->bg(Color::indexed(236)),
                    ),
                ), style: Style::of(size: Size::fill(), background: Color::indexed(236))),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));

        $needsDraw = false;
    });

    try {
        $stage->run($ctx);
    } finally {
        $simulation->cancel();
        $stream->stop();
    }

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
]))->default('capstone')->run(array_slice($_SERVER['argv'] ?? [], 1)));

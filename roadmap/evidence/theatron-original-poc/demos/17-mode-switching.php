#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\ModeDispatcher;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class AgentListPanel implements NormalModeHandler
{
    private(set) Signal $selected;
    private(set) Signal $dirty;

    /** @var list<string> */
    private array $agents = ['Thales', 'Archimedes', 'Pericles', 'Leonidas', 'Odysseus'];

    public function __construct()
    {
        $this->selected = new Signal(0);
        $this->dirty = new Signal(0);
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is('j') || $event->is(Key::Down)) {
            $this->selected->value = min(count($this->agents) - 1, $this->selected->value + 1);
            $this->dirty->value++;

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up)) {
            $this->selected->value = max(0, $this->selected->value - 1);
            $this->dirty->value++;

            return true;
        }

        return false;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        $rows = [];

        foreach ($this->agents as $i => $name) {
            $isSelected = $i === $this->selected->value;
            $style = $isSelected
                ? TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold()
                : TextStyle::new()->fg(Color::brightWhite());

            $rows[] = $ui->text(Line::from(
                Span::styled($isSelected ? ' > ' : '   ', $style),
                Span::styled($name, $style),
            ));
        }

        $borderColor = $focused ? Color::brightCyan() : Color::indexed(240);

        return $ui->panel('Agents', $ui->column(...$rows), style: Style::of(
            size: Size::fill(),
            border: Border::Rounded,
            color: $borderColor,
        ));
    }
}

final class MessageFeedPanel implements NormalModeHandler
{
    private(set) Signal $scroll;
    private(set) Signal $dirty;

    /** @var list<string> */
    private array $messages = [
        'Thales: The cosmos exhibits mathematical order.',
        'Archimedes: Give me a lever long enough...',
        'Pericles: Freedom is the sure possession of those who have the courage to defend it.',
        'Leonidas: Come and take them.',
        'Odysseus: Of all creatures that breathe and move upon the earth, nothing is bred weaker than man.',
        'Thales: All things are full of gods.',
        'Archimedes: Eureka! I have found it!',
        'Pericles: What you leave behind is not engraved in monuments.',
    ];

    public function __construct()
    {
        $this->scroll = new Signal(0);
        $this->dirty = new Signal(0);
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is('j') || $event->is(Key::Down)) {
            $this->scroll->value = min(count($this->messages) - 1, $this->scroll->value + 1);
            $this->dirty->value++;

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up)) {
            $this->scroll->value = max(0, $this->scroll->value - 1);
            $this->dirty->value++;

            return true;
        }

        return false;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        $rows = [];
        $start = $this->scroll->value;
        $visible = array_slice($this->messages, $start, 5);

        foreach ($visible as $i => $msg) {
            $idx = $start + $i;
            $style = $idx === $start
                ? TextStyle::new()->fg(Color::brightYellow())
                : TextStyle::new()->fg(Color::indexed(250));

            $rows[] = $ui->text(Line::from(Span::styled(" {$msg}", $style)));
        }

        $borderColor = $focused ? Color::brightYellow() : Color::indexed(240);

        return $ui->panel('Messages', $ui->column(...$rows), style: Style::of(
            size: Size::fill(),
            border: Border::Rounded,
            color: $borderColor,
        ));
    }
}

final class CommandInput implements InputTarget
{
    private(set) Signal $text;
    private(set) Signal $dirty;

    public function __construct()
    {
        $this->text = new Signal('');
        $this->dirty = new Signal(0);
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        if ($event->is(Key::Backspace)) {
            $this->text->value = mb_substr($this->text->value, 0, -1);
            $this->dirty->value++;

            return true;
        }

        if ($event->is(Key::Space)) {
            $this->text->value .= ' ';
            $this->dirty->value++;

            return true;
        }

        $char = $event->char();

        if ($char === null) {
            return false;
        }

        $this->text->value .= $char;
        $this->dirty->value++;

        return true;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        $borderColor = $focused ? Color::brightGreen() : Color::indexed(240);

        return $ui->panel(
            'Command',
            $ui->input(
                value: $this->text->value,
                prompt: 'agora> ',
                cursor: mb_strlen($this->text->value),
                style: Style::of(size: Size::fixed(1)),
            ),
            style: Style::of(size: Size::fixed(3), border: Border::Rounded, color: $borderColor),
        );
    }
}

exit(Archon::command('mode-switching', static function (CommandContext $ctx): int {
    $ui = new Ui();
    $stage = Stage::boot(new StageConfig(activeIntervalUs: 50_000));

    $agents = new AgentListPanel();
    $messages = new MessageFeedPanel();
    $command = new CommandInput();

    $focus = new FocusManager();
    $focus->register('agents', $agents);
    $focus->register('messages', $messages);
    $focus->register('command', $command);
    $focus->focus('agents');

    $dispatcher = new ModeDispatcher($focus);
    $needsDraw = true;

    $stage->onInput(static function (InputEvent $event) use ($dispatcher, $stage, &$needsDraw): void {
        if (!$event instanceof KeyEvent) {
            return;
        }

        if ($event->is('q') && $dispatcher->mode === InputMode::Normal) {
            $stage->stop();

            return;
        }

        if ($dispatcher->dispatch($event)) {
            $needsDraw = true;
        }
    });

    $w = $stage->width();
    $h = $stage->height();
    $mainRegion = $stage->region('main', \Phalanx\Theatron\Buffer\Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('status', \Phalanx\Theatron\Buffer\Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($mainRegion, $barRegion): void {
        $mainRegion->resize(\Phalanx\Theatron\Buffer\Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(\Phalanx\Theatron\Buffer\Rect::of(0, $nh - 1, $nw, 1));
    });

    $stage->onDraw(static function () use (
        $ui,
        $agents,
        $messages,
        $command,
        $focus,
        $dispatcher,
        $mainRegion,
        $barRegion,
        &$needsDraw,
    ): void {
        if (!$needsDraw) {
            return;
        }

        $activeName = $focus->activeName();

        $body = $ui->column(
            $ui->row(
                $agents->render($ui, $activeName === 'agents'),
                $messages->render($ui, $activeName === 'messages'),
            ),
            $command->render($ui, $activeName === 'command'),
        );

        $mainRegion->draw($body);

        $modeLabel = match ($dispatcher->mode) {
            InputMode::Normal => ' NORMAL ',
            InputMode::Insert => ' INSERT ',
        };

        $modeColor = match ($dispatcher->mode) {
            InputMode::Normal => Color::brightCyan(),
            InputMode::Insert => Color::brightGreen(),
        };

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(Line::from(
                    Span::styled($modeLabel, TextStyle::new()->fg(Color::black())->bg($modeColor)->bold()),
                    Span::styled(
                        " {$activeName} ",
                        TextStyle::new()->fg(Color::brightWhite())->bg(Color::indexed(236)),
                    ),
                    Span::styled(
                        ' h/l:panel j/k:scroll i:insert Esc:normal q:quit ',
                        TextStyle::new()->fg(Color::indexed(242))->bg(Color::indexed(236)),
                    ),
                ), style: Style::of(size: Size::fill(), background: Color::indexed(236))),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));

        $needsDraw = false;
    });

    $stage->run($ctx);

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
]))->default('mode-switching')->run(array_slice($_SERVER['argv'] ?? [], 1)));

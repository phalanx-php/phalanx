<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Component;

use Phalanx\Theatron\Demos\Capstone\Slice\TaskBoardSlice;
use Phalanx\Theatron\Demos\Capstone\Slice\TaskEntry;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class TaskBoardPanel implements NormalModeHandler
{
    private int $selected = 0;

    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        $tasks = $this->lens->handle(TaskBoardSlice::class)->value->tasks;
        $count = count($tasks);

        if ($count === 0) {
            return false;
        }

        if ($event->is('j') || $event->is(Key::Down)) {
            $this->selected = min($count - 1, $this->selected + 1);

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up)) {
            $this->selected = max(0, $this->selected - 1);

            return true;
        }

        return false;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        /** @var TaskBoardSlice $slice */
        $slice = $this->lens->handle(TaskBoardSlice::class)->value;
        $count = count($slice->tasks);
        $this->selected = min($this->selected, max(0, $count - 1));

        $rows = [];

        foreach ($slice->tasks as $i => $task) {
            $rows[] = $this->renderTask($ui, $task, $i === $this->selected);
        }

        if ($rows === []) {
            $rows[] = $ui->text(
                Line::from(Span::styled('  No tasks yet.', TextStyle::new()->fg(Color::indexed(242)))),
            );
        }

        $borderColor = $focused ? Color::brightMagenta() : Color::indexed(240);

        return $ui->panel('Tasks', $ui->column(...$rows), style: Style::of(
            size: Size::fill(),
            border: Border::Rounded,
            color: $borderColor,
        ));
    }

    private static function statusIcon(string $status): string
    {
        return match ($status) {
            'active' => '▶',
            'completed' => '✓',
            'pending' => '○',
            default => '?',
        };
    }

    private static function statusColor(string $status): Color
    {
        return match ($status) {
            'active' => Color::brightYellow(),
            'completed' => Color::brightGreen(),
            'pending' => Color::indexed(245),
            default => Color::indexed(240),
        };
    }

    private function renderTask(Ui $ui, TaskEntry $task, bool $isSelected): Renderable
    {
        $icon = self::statusIcon($task->status);
        $iconColor = self::statusColor($task->status);

        $titleStyle = $isSelected
            ? TextStyle::new()->fg(Color::black())->bg(Color::brightMagenta())->bold()
            : TextStyle::new()->fg(Color::brightWhite());

        $assigneeStyle = $isSelected
            ? $titleStyle
            : TextStyle::new()->fg(Color::indexed(242));

        return $ui->text(Line::from(
            Span::styled(" {$icon} ", TextStyle::new()->fg($iconColor)),
            Span::styled($task->title, $titleStyle),
            Span::styled(" @{$task->assignedTo}", $assigneeStyle),
        ));
    }
}

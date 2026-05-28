<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Component;

use Phalanx\Theatron\Demos\Capstone\Slice\AgentInfo;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentRegistrySlice;
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

final class AgentStatusPanel implements NormalModeHandler
{
    private int $selected = 0;

    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        $agents = $this->lens->handle(AgentRegistrySlice::class)->value->agents;
        $count = count($agents);

        if ($count === 0) {
            return false;
        }

        if ($event->is('j') || $event->is(\Phalanx\Theatron\Input\Key::Down)) {
            $this->selected = min($count - 1, $this->selected + 1);

            return true;
        }

        if ($event->is('k') || $event->is(\Phalanx\Theatron\Input\Key::Up)) {
            $this->selected = max(0, $this->selected - 1);

            return true;
        }

        return false;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        /** @var AgentRegistrySlice $slice */
        $slice = $this->lens->handle(AgentRegistrySlice::class)->value;
        $count = count($slice->agents);
        $this->selected = min($this->selected, max(0, $count - 1));

        $rows = [];
        $i = 0;

        foreach ($slice->agents as $agent) {
            $rows[] = $this->renderAgent($ui, $agent, $i === $this->selected);
            $i++;
        }

        if ($rows === []) {
            $rows[] = $ui->text(
                Line::from(Span::styled('  Awaiting agents...', TextStyle::new()->fg(Color::indexed(242)))),
            );
        }

        $borderColor = $focused ? Color::brightCyan() : Color::indexed(240);

        return $ui->panel('Agents', $ui->column(...$rows), style: Style::of(
            size: Size::fill(),
            border: Border::Rounded,
            color: $borderColor,
        ));
    }

    private static function statusColor(string $status): Color
    {
        return match ($status) {
            'online' => Color::brightGreen(),
            'working' => Color::brightYellow(),
            'idle' => Color::indexed(245),
            default => Color::indexed(240),
        };
    }

    private function renderAgent(Ui $ui, AgentInfo $agent, bool $isSelected): Renderable
    {
        $statusColor = self::statusColor($agent->status);
        $dot = Span::styled(' ● ', TextStyle::new()->fg($statusColor));

        $nameStyle = $isSelected
            ? TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold()
            : TextStyle::new()->fg(Color::brightWhite());

        $roleStyle = $isSelected
            ? $nameStyle
            : TextStyle::new()->fg(Color::indexed(242));

        return $ui->text(Line::from(
            $dot,
            Span::styled($agent->name, $nameStyle),
            Span::styled(" [{$agent->role}]", $roleStyle),
        ));
    }
}

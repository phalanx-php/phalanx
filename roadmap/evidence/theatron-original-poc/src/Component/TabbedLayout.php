<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Theatron\Focus\Focusable;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\ModeDispatcher;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class TabbedLayout implements InputTarget
{
    private(set) FocusManager $focus;
    private(set) ModeDispatcher $dispatcher;

    /** @var list<array{string, Focusable}> */
    private array $children = [];

    public function __construct(
        private(set) TabbedLayoutConfig $config = new TabbedLayoutConfig(),
    ) {
        $this->focus = new FocusManager();
        $this->dispatcher = new ModeDispatcher($this->focus);
    }

    public function add(string $label, Focusable $child): self
    {
        $this->children[] = [$label, $child];
        $this->focus->register($label, $child);

        return $this;
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        return $this->dispatcher->dispatch($event);
    }

    public function renderNavBar(Ui $ui): Renderable
    {
        $activeName = $this->focus->activeName();
        $spans = [Span::styled(' ', TextStyle::new())];

        foreach ($this->children as [$label, $_]) {
            $isActive = $label === $activeName;
            $style = $isActive
                ? TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold()
                : TextStyle::new()->fg(Color::indexed(245));

            $spans[] = Span::styled(" {$label} ", $style);
        }

        return $ui->text(Line::from(...$spans), Style::of(size: Size::fill()));
    }

    public function renderModeIndicator(Ui $ui): Renderable
    {
        $label = match ($this->dispatcher->mode) {
            InputMode::Normal => ' NORMAL ',
            InputMode::Insert => ' INSERT ',
        };

        $color = match ($this->dispatcher->mode) {
            InputMode::Normal => Color::brightCyan(),
            InputMode::Insert => Color::brightGreen(),
        };

        $focusName = $this->focus->activeName() ?? '';

        return $ui->text(Line::from(
            Span::styled($label, TextStyle::new()->fg(Color::black())->bg($color)->bold()),
            Span::styled(" {$focusName}", TextStyle::new()->fg(Color::indexed(245))),
        ), Style::of(size: Size::fill()));
    }

    public function activeName(): ?string
    {
        return $this->focus->activeName();
    }

    public function mode(): InputMode
    {
        return $this->dispatcher->mode;
    }

    public function isQuit(KeyEvent $event): bool
    {
        return $event->is('q') && $this->dispatcher->mode === InputMode::Normal;
    }
}

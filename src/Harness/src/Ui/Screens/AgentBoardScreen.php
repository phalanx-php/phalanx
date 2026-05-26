<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Screens;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Slices\AgentRegistrySlice;
use Phalanx\Harness\Ui\Slices\AgentSummary;
use Phalanx\Theatron\Context\RenderEnvironment;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Kit\StatusBar;
use Phalanx\Theatron\Layout\Border;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\row;
use function Phalanx\Theatron\Ui\text;

class AgentBoardScreen implements Screen, HasStatusBar, HasFocusables, NormalModeHandler
{
    private int $selectedIndex = 0;

    public function __construct(
        private(set) AppStore $store,
    ) {
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        $agents = $this->store->agents;

        return column(
            self::renderAgentCards($ctx->theme, $agents, $this->selectedIndex),
        );
    }

    public function statusBar(): Renderable
    {
        $inputMode = $this->store->inputMode;
        $agentCount = count($this->store->agents->agents);

        return StatusBar::new(theme: RenderEnvironment::theme())
            ->section($inputMode->mode->label(), $inputMode->mode->color())
            ->left('Board')
            ->right(sprintf('Agents: %d', $agentCount))
            ->render();
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['agents', $this]];
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        $count = count($this->store->agents->agents);

        if ($count === 0) {
            return false;
        }

        if ($event->is('j') || $event->is(Key::Down)) {
            $this->selectedIndex = min($count - 1, $this->selectedIndex + 1);

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up)) {
            $this->selectedIndex = max(0, $this->selectedIndex - 1);

            return true;
        }

        return false;
    }

    private static function renderAgentCards(
        Theme $theme,
        AgentRegistrySlice $agents,
        int $selectedIndex,
    ): Renderable {
        if ($agents->agents === []) {
            return text(
                Line::from(Span::styled('No agents registered', $theme->muted)),
            );
        }

        $cards = array_map(
            static fn (AgentSummary $agent, int $i) => self::renderAgentCard(
                $theme,
                $agent,
                $agents->activeAgentId,
                $i === $selectedIndex,
            ),
            $agents->agents,
            array_keys($agents->agents),
        );

        return row(...$cards);
    }

    private static function renderAgentCard(
        Theme $theme,
        AgentSummary $agent,
        ?string $activeAgentId,
        bool $selected,
    ): Renderable {
        $isActive = $agent->id === $activeAgentId;

        $borderColor = match (true) {
            $isActive => $theme->accent->foreground ?? $theme->border,
            $selected => Color::brightYellow(),
            default => $theme->border,
        };

        $panelStyle = TdomStyle::of(border: Border::Rounded, color: $borderColor);

        $lines = [];

        $nameStyle = $isActive
            ? $theme->accent->bold()
            : $theme->bright;
        $lines[] = text(Line::from(Span::styled($agent->name, $nameStyle)));

        $lines[] = text(Line::from(Span::styled($agent->id, $theme->muted)));

        if ($agent->capabilities !== []) {
            $lines[] = text(
                Line::from(Span::styled('Capabilities:', $theme->subtle)),
            );

            foreach ($agent->capabilities as $capability) {
                $lines[] = text(
                    Line::from(
                        Span::styled('  • ', $theme->hint),
                        Span::styled($capability, $theme->bright),
                    ),
                );
            }
        }

        if ($isActive) {
            $lines[] = text(
                Line::from(Span::styled('Active', $theme->accent->bold())),
            );
        }

        return panel($agent->name, column(...$lines), style: $panelStyle);
    }
}

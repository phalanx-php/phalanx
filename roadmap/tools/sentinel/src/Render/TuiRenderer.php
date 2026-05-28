<?php

declare(strict_types=1);

namespace Sentinel\Render;

use Phalanx\Theatron\Style\Palette;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Surface\Surface;
use Phalanx\Theatron\Widget\ScrollableText;
use Phalanx\Theatron\Widget\StatusBar;
use Phalanx\Theatron\Widget\Text\Line;
use Phalanx\Theatron\Widget\Text\Span;
use Sentinel\Watcher\ChangeKind;
use Sentinel\Watcher\FileChange;

final class TuiRenderer implements ReviewRenderer
{
    /** @var array<string, ScrollableText> */
    private array $agentPanels = [];

    /** @var array<string, string> */
    private array $agentColors = [];

    private float $startTime;
    private int $agentCount = 0;

    public function __construct(
        private Surface $surface,
        private StatusBar $statusBar,
    ) {
        $this->startTime = microtime(true);
    }

    public function registerAgentPanel(string $agentName, string $color, ScrollableText $panel): void
    {
        $this->agentPanels[$agentName] = $panel;
        $this->agentColors[$agentName] = $color;
        $this->agentCount++;
    }

    public function banner(): void
    {
        $this->statusBar->setLeft(
            Span::styled(' SENTINEL', Style::new()->fg('cyan')->bold()),
            Span::styled(' -- Phalanx Agent Watcher', Palette::muted()),
        );
        $this->surface->getRegion('status')?->invalidate();
    }

    public function agentRegistered(string $name, string $color): void
    {
        $this->broadcastToAll(
            Line::from(
                Span::styled('+ ', Palette::muted()),
                Span::styled($name, Style::new()->fg($color)),
                Span::styled(' registered', Palette::muted()),
            )
        );
    }

    public function watchingDirectory(string $path): void
    {
        $this->statusBar->setRight(
            Span::styled('watching ', Palette::muted()),
            Span::styled($path, Style::new()->fg('bright-white')),
            Span::plain(' '),
        );
        $this->surface->getRegion('status')?->invalidate();
    }

    public function ready(): void
    {
        $this->broadcastToAll(
            Line::from(
                Span::styled('Ready.', Style::new()->fg('green')),
                Span::styled(' Watching for changes.', Palette::muted()),
            )
        );
    }

    /** @param list<FileChange> $changes */
    public function fileChanges(array $changes): void
    {
        $elapsed = $this->elapsed();

        $header = Line::from(
            Span::styled("[{$elapsed}] ", Palette::muted()),
            Span::styled('FILE CHANGE', Style::new()->fg('yellow')->bold()),
            Span::styled(' (' . count($changes) . ' files)', Palette::muted()),
        );

        $this->broadcastToAll($header);

        foreach ($changes as $change) {
            [$symbol, $color] = match ($change->kind) {
                ChangeKind::Created => ['+', 'green'],
                ChangeKind::Modified => ['~', 'yellow'],
                ChangeKind::Deleted => ['-', 'red'],
                ChangeKind::Renamed => ['>', 'cyan'],
            };

            $this->broadcastToAll(
                Line::from(
                    Span::styled("  {$symbol} ", Style::new()->fg($color)),
                    Span::styled($change->path, Style::new()->fg('bright-white')),
                )
            );
        }
    }

    public function agentFeedback(string $agentName, string $color, string $text): void
    {
        $panel = $this->agentPanels[$agentName] ?? null;

        if ($panel === null) {
            return;
        }

        $elapsed = $this->elapsed();
        $panel->appendLine(Line::from(
            Span::styled("[{$elapsed}] ", Palette::muted()),
        ));

        foreach (explode("\n", $text) as $line) {
            $panel->appendLine(self::highlightSeverity($line));
        }

        $panel->appendLine(Line::plain(''));

        $this->invalidateAgent($agentName);
    }

    public function humanMessage(string $message): void
    {
        $this->broadcastToAll(Line::plain(''));
    }

    public function externalMessage(string $from, string $message): void
    {
        $elapsed = $this->elapsed();

        $this->broadcastToAll(Line::from(
            Span::styled("[{$elapsed}] ", Palette::muted()),
            Span::styled($from, Style::new()->fg('cyan')),
        ));

        $this->broadcastToAll(Line::from(
            Span::styled("  {$message}", Style::new()),
        ));

        $this->broadcastToAll(Line::plain(''));
    }

    public function prompt(): void
    {
    }

    public function reviewComplete(int $reviewNumber, ?float $elapsedSeconds = null, ?int $totalTokens = null): void
    {
        $this->broadcastToAll(Line::from(
            Span::styled("--- review #{$reviewNumber} complete ---", Palette::muted()),
        ));

        $this->statusBar->setLeft(
            Span::styled(' SENTINEL', Style::new()->fg('cyan')->bold()),
            Span::styled(" | review #{$reviewNumber}", Palette::muted()),
            Span::styled(' | ', Palette::muted()),
            Span::styled($this->elapsed(), Palette::muted()),
        );
        $this->surface->getRegion('status')?->invalidate();
    }

    public function info(string $message): void
    {
        $this->broadcastToAll(Line::from(
            Span::styled($message, Palette::muted()),
        ));
    }

    public function error(string $message): void
    {
        $this->broadcastToAll(Line::from(
            Span::styled('[error] ', Style::new()->fg('bright-red')->bold()),
            Span::styled($message, Style::new()->fg('red')),
        ));
    }

    public function shutdown(): void
    {
        $this->broadcastToAll(Line::from(
            Span::styled('Sentinel stopped.', Palette::muted()),
        ));
    }

    private function broadcastToAll(Line $line): void
    {
        foreach ($this->agentPanels as $name => $panel) {
            $panel->appendLine($line);
            $this->invalidateAgent($name);
        }
    }

    private function invalidateAgent(string $agentName): void
    {
        $this->surface->getRegion("agent-{$agentName}")?->invalidate();
    }

    private function elapsed(): string
    {
        $seconds = microtime(true) - $this->startTime;

        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) ($seconds / 60);
        $remaining = $seconds - ($minutes * 60);

        return sprintf('%dm%02ds', $minutes, $remaining);
    }

    private static function highlightSeverity(string $line): Line
    {
        $severities = [
            'CRITICAL' => Style::new()->fg('bright-white')->bg('red')->bold(),
            'HIGH' => Style::new()->fg('bright-red')->bold(),
            'MEDIUM' => Style::new()->fg('yellow'),
            'LOW' => Style::new()->fg('cyan'),
            'INFO' => Palette::muted(),
        ];

        foreach ($severities as $label => $style) {
            if (str_contains($line, "[{$label}]")) {
                $parts = explode("[{$label}]", $line, 2);

                return Line::from(
                    Span::plain($parts[0]),
                    Span::styled("[{$label}]", $style),
                    Span::plain($parts[1] ?? ''),
                );
            }
        }

        return Line::plain($line);
    }
}

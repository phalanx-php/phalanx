<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Screens;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Render\ConversationEventFormatter;
use Phalanx\Harness\Ui\Slices\ActivityStatus;
use Phalanx\Harness\Ui\Slices\DevToolsTab;
use Phalanx\Harness\Ui\Slices\EffectLogEntry;
use Phalanx\Harness\Ui\Slices\LlmRequestEntry;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Component\ComponentTreeNode;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\RefreshesPeriodically;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Kit\Metrics;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\SignalRegistry;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\text;

class DevToolsScreen implements
    Screen,
    HasStatusBar,
    HasFocusables,
    DeclaresBindings,
    NormalModeHandler,
    RefreshesPeriodically
{
    public function __construct(
        private(set) AppStore $store,
        private(set) MountSystem $mountSystem,
        private(set) Navigator $navigator,
        private(set) ?SignalRegistry $registry = null,
    ) {
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        return column(
            self::row(Line::from(
                Span::styled('  ── DevTools ─────────────────────────────', self::headerStyle()),
            )),
            self::row($this->tabs()),
            self::divider(100),
            self::blank(),
            $this->tabContent($ctx->theme),
        );
    }

    public function statusBar(): Renderable
    {
        return text(
            Line::from(
                Span::styled('  ↑', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' req', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('↓', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' req', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('←', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' tab', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('→', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' tab', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('Enter', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' detail', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('^C', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' quit', TextStyle::new()->fg(Color::indexed(250))),
                self::pipe(),
                Span::styled('Esc', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(' back', TextStyle::new()->fg(Color::indexed(250))),
            ),
            TdomStyle::of(size: Size::fixed(1)),
        );
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['devtools', $this]];
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return [
            Binding::key(Key::Up)->label('req'),
            Binding::key(Key::Down)->label('req'),
            Binding::key(Key::Left)->label('tab'),
            Binding::key(Key::Right)->label('tab'),
            Binding::key(Key::Enter)->label('detail'),
            Binding::key(Key::Escape)->back()->label('back'),
        ];
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is(Key::Left)) {
            $this->store->devtools = $this->store->devtools->prevTab();

            return true;
        }

        if ($event->is(Key::Right)) {
            $this->store->devtools = $this->store->devtools->nextTab();

            return true;
        }

        if ($event->is(Key::Up) && $this->requestsVisible()) {
            $this->store->requests = $this->store->requests->focusUp();

            return true;
        }

        if ($event->is(Key::Down) && $this->requestsVisible()) {
            $this->store->requests = $this->store->requests->focusDown();

            return true;
        }

        if ($event->is(Key::Enter) && $this->requestsVisible() && $this->store->requests->focused() !== null) {
            $this->store->requests = $this->store->requests->selectFocusedForDetail();
            $this->navigator->go(LlmRequestDetailScreen::class);

            return true;
        }

        return false;
    }

    public function refreshIntervalSeconds(): ?float
    {
        return match ($this->store->devtools->activeTab) {
            DevToolsTab::Metrics, DevToolsTab::Signals, DevToolsTab::Tree => 0.25,
            default => null,
        };
    }

    private static function requestRow(LlmRequestEntry $entry, bool $focused): Renderable
    {
        $status = match (true) {
            $entry->error !== null => 'ERR',
            $entry->status !== null => (string) $entry->status,
            default => '...',
        };
        $time = $entry->elapsedMs !== null ? number_format($entry->elapsedMs / 1000, 1) . 's' : '...';
        $tokens = $entry->tokenCount !== null ? $entry->tokenCount . ' tok' : '';

        return self::row(Line::from(
            Span::styled(
                $focused ? ' ▶ ' : '   ',
                TextStyle::new()->fg($focused ? Color::indexed(252) : Color::indexed(242)),
            ),
            Span::styled($entry->method . ' ' . $entry->path . '  ', TextStyle::new()->fg(Color::indexed(252))->bold()),
            Span::styled(trim($status . ' • ' . $time . ' • ' . $tokens), TextStyle::new()->fg(Color::indexed(245))),
        ));
    }

    private static function kv(string $key, string $value): Renderable
    {
        return self::row(Line::from(
            Span::styled("    {$key}: ", TextStyle::new()->fg(Color::indexed(245))),
            Span::styled($value, TextStyle::new()->fg(Color::indexed(250))),
        ));
    }

    private static function row(Line $line): Renderable
    {
        return text($line, TdomStyle::of(size: Size::fixed(1)));
    }

    private static function blank(): Renderable
    {
        return self::row(Line::plain(''));
    }

    private static function divider(int $width): Renderable
    {
        return self::row(Line::from(
            Span::styled(str_repeat('─', $width), TextStyle::new()->fg(Color::indexed(236))),
        ));
    }

    private static function pipe(): Span
    {
        return Span::styled('  │  ', TextStyle::new()->fg(Color::indexed(238)));
    }

    private static function headerStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(252))->bold();
    }

    private static function dimStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(242));
    }

    private static function statusLabel(ActivityStatus $status): string
    {
        return match ($status) {
            ActivityStatus::Idle => 'Idle',
            ActivityStatus::Running => 'Running',
            ActivityStatus::AwaitingApproval => 'Awaiting Approval',
            ActivityStatus::Completed => 'Completed',
            ActivityStatus::Failed => 'Failed',
            ActivityStatus::Cancelled => 'Cancelled',
        };
    }

    private function tabs(): Line
    {
        $spans = [Span::plain('  ')];

        foreach (DevToolsTab::cases() as $tab) {
            $style = $tab === $this->store->devtools->activeTab
                ? TextStyle::new()->fg(Color::indexed(255))->bold()->underline()
                : TextStyle::new()->fg(Color::indexed(245));
            $spans[] = Span::styled(' ' . $tab->value . ' ', $style);
        }

        return Line::from(...$spans);
    }

    private function tabContent(Theme $theme): Renderable
    {
        return match ($this->store->devtools->activeTab) {
            DevToolsTab::Requests => column(...$this->requestRows()),
            DevToolsTab::Signals => column(...$this->signalRows($theme)),
            DevToolsTab::Tree => column(...$this->treeRows($theme)),
            DevToolsTab::Store => column(...$this->storeRows()),
            DevToolsTab::Metrics => column(...$this->metricRows()),
        };
    }

    private function requestsVisible(): bool
    {
        return $this->store->devtools->activeTab === DevToolsTab::Requests;
    }

    /** @return list<Renderable> */
    private function metricRows(): array
    {
        $conversation = $this->store->conversation;
        $workspaceView = $this->store->workspaceView;
        $activity = $this->store->activity;
        $input = $this->store->input;
        $rows = [
            self::row(Line::from(Span::styled('  Store Slices', self::headerStyle()))),
            self::kv(
                'repl.convo',
                count($conversation->turns) . ' turns, scroll=' . $workspaceView->chatScrollOffset
                    . ', selected=' . ($workspaceView->selectedTurnId ?? 'none'),
            ),
            self::kv('repl.status', 'Agent [' . strtolower($activity->status->name) . ']'),
            self::kv('repl.input', mb_strlen($input->text) . ' chars'),
            self::kv('repl.requests', count($this->store->requests->entries) . ' entries'),
            self::kv('repl.effects', count($this->store->effects->entries) . ' entries'),
            self::blank(),
        ];

        if ($conversation->turns !== []) {
            $rows[] = self::row(Line::from(Span::styled('  Exchange History', self::headerStyle())));
            foreach ($conversation->turns as $i => $turn) {
                $rows[] = self::kv(
                    "  [{$i}]",
                    $turn->id . ' user=' . mb_strlen($turn->userText) . 'c events=' . count($turn->events),
                );

                foreach (array_slice($turn->projectionEvents(), -3) as $event) {
                    $rows[] = self::kv('     ' . $event->id, ConversationEventFormatter::summary($event));
                }
            }
            $rows[] = self::blank();
        }

        $memReal = memory_get_usage(true);
        $memZend = memory_get_usage(false);
        $peakReal = memory_get_peak_usage(true);
        $peakZend = memory_get_peak_usage(false);

        $rows[] = self::row(Line::from(Span::styled('  Memory', self::headerStyle())));
        $rows[] = self::kv('zmm used', Metrics::memory($memZend));
        $rows[] = self::kv('real used', Metrics::memory($memReal));
        $rows[] = self::kv('zmm peak', Metrics::memory($peakZend));
        $rows[] = self::kv('real peak', Metrics::memory($peakReal));
        $rows[] = self::blank();

        $gc = gc_status();
        $rows[] = self::row(Line::from(Span::styled('  Garbage Collector', self::headerStyle())));
        $rows[] = self::kv('runs', (string) $gc['runs']);
        $rows[] = self::kv('collected', (string) $gc['collected']);
        $rows[] = self::kv('root buffer', $gc['roots'] . ' / ' . $gc['buffer_size'] . ' slots');
        $rows[] = self::blank();

        $rows[] = self::row(Line::from(Span::styled('  Runtime', self::headerStyle())));
        $rows[] = self::kv('php', PHP_VERSION . ' (' . PHP_SAPI . ')');
        $rows[] = self::kv('os', PHP_OS . ' ' . php_uname('m'));
        $rows[] = self::kv('pid', (string) getmypid());
        $rows[] = self::kv('components', (string) count($this->mountSystem->mounted()));
        $rows[] = self::kv('signals', (string) ($this->registry?->count() ?? 0));

        return $rows;
    }

    /** @return list<Renderable> */
    private function requestRows(): array
    {
        $rows = [
            self::row(Line::from(Span::styled(' LLM Requests', self::headerStyle()))),
            self::blank(),
        ];

        if ($this->store->requests->entries === []) {
            $rows[] = self::row(Line::from(Span::styled('  (no requests yet)', self::dimStyle())));

            return $rows;
        }

        foreach ($this->store->requests->entries as $i => $entry) {
            $rows[] = self::requestRow($entry, $i === $this->store->requests->focusedIndex);
        }

        return $rows;
    }

    /** @return list<Renderable> */
    private function signalRows(Theme $theme): array
    {
        $snapshots = $this->registry?->snapshot() ?? [];
        $rows = [
            self::row(Line::from(
                Span::styled('  Signals', self::headerStyle()),
                Span::styled('  Value', $theme->subtle->bold()),
                Span::styled('  Subs', $theme->subtle->bold()),
            )),
            self::blank(),
        ];

        foreach ($snapshots as $snapshot) {
            $nameStyle = $snapshot->isDisposed ? $theme->muted : $theme->accent;
            $valueStyle = $snapshot->isDisposed ? $theme->muted : $theme->bright;
            $state = $snapshot->isDisposed ? ' disposed' : '';

            $rows[] = self::row(Line::from(
                Span::styled("  {$snapshot->label}", $nameStyle),
                Span::styled(' = ', $theme->subtle),
                Span::styled($snapshot->value, $valueStyle),
                Span::styled(" ({$snapshot->subscriberCount}){$state}", $theme->muted),
            ));
        }

        if ($snapshots === []) {
            $rows[] = self::row(Line::from(Span::styled('  No signals registered', self::dimStyle())));
        }

        return $rows;
    }

    /** @return list<Renderable> */
    private function treeRows(Theme $theme): array
    {
        $nodes = [];

        foreach ($this->mountSystem->mounted() as $mounted) {
            $nodes[] = new ComponentTreeNode(
                class: $mounted->component::class,
                signalCount: $mounted->signalCount,
                subscriptionCount: $mounted->subscriptionCount,
            );
        }

        $rows = [
            self::row(Line::from(Span::styled('  Component Tree', self::headerStyle()))),
            self::blank(),
        ];

        foreach ($nodes as $node) {
            $pos = strrpos($node->class, '\\');
            $shortClass = $pos !== false ? substr($node->class, $pos + 1) : $node->class;

            $rows[] = self::row(Line::from(
                Span::styled("  {$shortClass}", $theme->accent->bold()),
                Span::styled(" sig:{$node->signalCount}", $theme->muted),
                Span::styled(" sub:{$node->subscriptionCount}", $theme->muted),
            ));
        }

        if ($nodes === []) {
            $rows[] = self::row(Line::from(Span::styled('  No components mounted', self::dimStyle())));
        }

        return $rows;
    }

    /** @return list<Renderable> */
    private function storeRows(): array
    {
        $conversation = $this->store->conversation;
        $agents = $this->store->agents;
        $activity = $this->store->activity;
        $effects = $this->store->effects;

        $rows = [
            self::row(Line::from(Span::styled('  Store Slices', self::headerStyle()))),
            self::blank(),
            self::row(Line::from(Span::styled('  ConversationSlice', self::headerStyle()))),
            self::kv('messages', (string) count($conversation->messages)),
            self::kv('turns', (string) count($conversation->turns)),
            self::kv('streaming', $conversation->isStreaming ? 'yes' : 'no'),
            self::kv('thinking', (string) mb_strlen($conversation->thinkingBuffer)),
            self::blank(),
            self::row(Line::from(Span::styled('  AgentRegistrySlice', self::headerStyle()))),
            self::kv('agents', (string) count($agents->agents)),
            self::kv('active', $agents->activeAgentId ?? 'none'),
            self::blank(),
            self::row(Line::from(Span::styled('  ActivitySlice', self::headerStyle()))),
            self::kv('status', self::statusLabel($activity->status)),
            self::kv('in', (string) $activity->inputTokens),
            self::kv('out', (string) $activity->outputTokens),
            self::kv('total', (string) $activity->totalTokens),
        ];

        $rows[] = self::blank();
        $rows[] = self::row(Line::from(Span::styled('  Conversation Events', self::headerStyle())));

        $events = [];
        foreach ($conversation->turns as $turn) {
            foreach ($turn->projectionEvents() as $event) {
                $events[] = $event;
            }
        }

        if ($events === []) {
            $rows[] = self::kv('events', 'none');
        } else {
            foreach (array_slice($events, -8) as $event) {
                $rows[] = self::kv($event->id, ConversationEventFormatter::detail($event));
            }
        }

        $rows[] = self::blank();
        $rows[] = self::row(Line::from(Span::styled('  EffectLogSlice', self::headerStyle())));

        if ($effects->entries === []) {
            $rows[] = self::kv('effects', 'none');

            return $rows;
        }

        foreach (array_slice($effects->entries, -6) as $entry) {
            $rows[] = self::kv(
                $entry->effectId,
                $this->effectLogSummary($entry),
            );
        }

        return $rows;
    }

    private function effectLogSummary(EffectLogEntry $entry): string
    {
        $parts = [
            $entry->status->value . ' ' . $entry->kind . ' [' . $entry->hazard . ']',
            $entry->summary,
        ];

        if ($entry->grantId !== null) {
            $parts[] = 'grant ' . $entry->grantId;
        }

        if ($entry->durationMs !== null) {
            $parts[] = $entry->durationMs . 'ms';
        }

        if ($entry->reasonCodes !== []) {
            $parts[] = implode(', ', $entry->reasonCodes);
        }

        if ($entry->errorClass !== null) {
            $parts[] = $entry->errorClass;
        }

        if ($entry->arguments !== []) {
            $parts[] = 'args ' . json_encode($entry->arguments, JSON_THROW_ON_ERROR);
        }

        return implode(' · ', $parts);
    }
}

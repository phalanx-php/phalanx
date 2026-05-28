<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedViewSlice;
use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestEntry;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class DevToolsOverlay
{
    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function render(Ui $ui, int $width, int $height): Renderable
    {
        $convo = $this->lens->handle(ConvoSlice::class)->value;
        $input = $this->lens->handle(InputSlice::class)->value;
        $agent = $this->lens->handle(AgentStatusSlice::class)->value;
        $focused = $this->lens->handle(FocusedViewSlice::class)->value;
        $settings = $this->lens->handle(SettingsSlice::class)->value;
        $wrapWidth = max(20, $width - 4);

        $rows = [];

        $h = TextStyle::new()->fg(Color::indexed(252))->bold();
        $l = TextStyle::new()->fg(Color::indexed(245));
        $v = TextStyle::new()->fg(Color::indexed(250));
        $dim = TextStyle::new()->fg(Color::indexed(242));
        $warn = TextStyle::new()->fg(Color::indexed(248));

        $sepWidth = min($wrapWidth, 50);
        $rows[] = self::row($ui, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", 2) . ' DevTools ' . str_repeat("\u{2500}", max(0, $sepWidth - 12)), TextStyle::new()->fg(Color::indexed(248))->bold()),
        ));
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        $rows[] = self::row($ui, Line::from(Span::styled('  Store Slices', $h)));
        $rows[] = self::kv($ui, 'repl.convo', $l, count($convo->history) . ' exchanges, scroll=' . $convo->scrollOffset . ', expanded=' . ($convo->expandedIndex ?? 'none'), $v);
        $rows[] = self::kv($ui, 'repl.status', $l, "{$agent->agentName} ({$agent->role}) [{$agent->status}]", $v);
        $rows[] = self::kv($ui, 'repl.input', $l, mb_strlen($input->text) . ' chars' . ($input->text !== '' ? ': "' . mb_substr($input->text, 0, 30) . '"' : ''), $v);
        $rows[] = self::kv($ui, 'repl.focused', $l, $focused->activePane->name . ', scroll=' . $focused->scrollPosition . ($focused->searchQuery !== null ? ", search=\"{$focused->searchQuery}\" [{$focused->searchMatchIndex}/{$focused->totalMatches}]" : ''), $v);
        $rows[] = self::kv($ui, 'repl.settings', $l, $settings->activeTab->value . ' tab, item=' . $settings->selectedItem, $v);

        $rows[] = self::row($ui, Line::from(Span::plain('')));

        if ($convo->activeTurn !== null) {
            $turn = $convo->activeTurn;
            $rows[] = self::row($ui, Line::from(Span::styled('  Active Turn', $h)));
            $rows[] = self::kv($ui, 'user_msg', $l, mb_strlen($turn->userMessage) . ' chars', $v);
            $rows[] = self::kv($ui, 'streamed', $l, mb_strlen($turn->streamedText) . ' chars', $v);
            $rows[] = self::kv($ui, 'tool_calls', $l, count($turn->toolCalls) . ' active', $v);
            $rows[] = self::kv($ui, 'thinking', $l, $turn->thinkingContent !== null ? mb_strlen($turn->thinkingContent) . ' chars' : 'none', $v);
            $rows[] = self::kv($ui, 'complete', $l, $turn->isComplete ? 'yes' : 'no', $v);
            $rows[] = self::row($ui, Line::from(Span::plain('')));
        }

        if ($convo->history !== []) {
            $rows[] = self::row($ui, Line::from(Span::styled('  Exchange History', $h)));
            $totalUserChars = 0;
            $totalAssistChars = 0;
            $totalToolCalls = 0;

            foreach ($convo->history as $i => $ex) {
                $userLen = mb_strlen($ex->userPreview);
                $assistLen = mb_strlen($ex->assistantPreview);
                $tools = $ex->toolCallCount;
                $totalUserChars += $userLen;
                $totalAssistChars += $assistLen;
                $totalToolCalls += $tools;

                $rows[] = self::kv($ui, "  [{$i}]", $dim, "user={$userLen}c assist={$assistLen}c tools={$tools}", $v);
            }

            $rows[] = self::kv($ui, '  totals', $dim, "user={$totalUserChars}c assist={$totalAssistChars}c tools={$totalToolCalls}", $warn);
            $rows[] = self::row($ui, Line::from(Span::plain('')));
        }

        $rows[] = self::row($ui, Line::from(Span::styled('  Memory (ZMM)', $h)));
        $memAllocated = memory_get_usage(true);
        $memUsed = memory_get_usage(false);
        $memAllocPeak = memory_get_peak_usage(true);
        $memUsedPeak = memory_get_peak_usage(false);
        $fragmentation = $memAllocated > 0 ? ($memAllocated - $memUsed) : 0;
        $fragPct = $memAllocated > 0 ? round($fragmentation / $memAllocated * 100, 1) : 0;
        $usagePct = $memAllocated > 0 ? round($memUsed / $memAllocated * 100, 1) : 0;

        $memLimit = ini_get('memory_limit');
        $limitBytes = self::parseMemoryLimit($memLimit ?: '-1');
        $limitStr = $limitBytes > 0 ? self::formatBytes($limitBytes) : 'unlimited';
        $limitUsed = $limitBytes > 0 ? round($memAllocated / $limitBytes * 100, 1) . '%' : 'n/a';

        $rows[] = self::kv($ui, 'heap used', $l, self::formatBytes($memUsed) . ' / ' . self::formatBytes($memAllocated) . " ({$usagePct}% dense)", $v);
        $rows[] = self::kv($ui, 'heap peak', $l, self::formatBytes($memUsedPeak) . ' / ' . self::formatBytes($memAllocPeak), $v);
        $rows[] = self::kv($ui, 'fragmentation', $l, self::formatBytes($fragmentation) . " ({$fragPct}% wasted in ZMM chunks)", $fragPct > 25 ? $warn : $v);
        $rows[] = self::kv($ui, 'limit', $l, "{$limitStr} ({$limitUsed} consumed)", $v);

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::row($ui, Line::from(Span::styled('  Garbage Collector', $h)));
        $gc = gc_status();
        $rows[] = self::kv($ui, 'runs', $l, (string) $gc['runs'], $v);
        $rows[] = self::kv($ui, 'collected', $l, (string) $gc['collected'], $v);
        $rows[] = self::kv($ui, 'root buffer', $l, $gc['roots'] . ' / ' . ($gc['buffer_size'] ?? 10000) . ' slots', $v);
        $rows[] = self::kv($ui, 'threshold', $l, (string) ($gc['threshold'] ?? 'n/a'), $v);
        $rows[] = self::kv($ui, 'running', $l, ($gc['running'] ?? false) ? 'yes' : 'no', $v);
        $rows[] = self::kv($ui, 'protected', $l, ($gc['protected'] ?? false) ? 'yes' : 'no', $v);

        if (isset($gc['application_time'])) {
            $appTime = round($gc['application_time'] * 1000, 2);
            $collTime = round(($gc['collector_time'] ?? 0) * 1000, 2);
            $freeTime = round(($gc['free_time'] ?? 0) * 1000, 2);
            $dtrTime = round(($gc['destructor_time'] ?? 0) * 1000, 2);
            $totalGcMs = $collTime + $freeTime + $dtrTime;
            $rows[] = self::kv($ui, 'app time', $l, "{$appTime}ms", $v);
            $rows[] = self::kv($ui, 'gc time', $l, "{$totalGcMs}ms (collect={$collTime} free={$freeTime} dtor={$dtrTime})", $totalGcMs > 10 ? $warn : $v);
        }

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::row($ui, Line::from(Span::styled('  Runtime', $h)));
        $rows[] = self::kv($ui, 'php', $l, PHP_VERSION . ' (' . PHP_SAPI . ')', $v);
        $rows[] = self::kv($ui, 'os', $l, PHP_OS . ' ' . php_uname('m'), $v);
        $rows[] = self::kv($ui, 'pid', $l, (string) getmypid(), $v);
        $rows[] = self::kv($ui, 'zend_mm', $l, ini_get('zend.enable_gc') ? 'gc enabled' : 'gc disabled', $v);
        $rows[] = self::kv($ui, 'opcache', $l, self::opcacheStatus(), $v);
        $rows[] = self::kv($ui, 'extensions', $l, self::keyExtensions(), $v);

        $leftRows = array_slice($rows, 0, $height);
        $leftColumn = new ColumnElement($leftRows, Style::of(size: Size::percent(60)));

        $requests = $this->lens->handle(LlmRequestSlice::class)->value;
        $rightColumn = new ColumnElement(
            $this->renderRequestCards($ui, $requests, $height),
            Style::of(size: Size::percent(40)),
        );

        return new RowElement([$leftColumn, $rightColumn], Style::of(size: Size::fill()));
    }

    /** @return list<Renderable> */
    private function renderRequestCards(Ui $ui, LlmRequestSlice $requests, int $height): array
    {
        $rows = [];
        $h = TextStyle::new()->fg(Color::indexed(252))->bold();
        $dim = TextStyle::new()->fg(Color::indexed(242));

        $rows[] = self::row($ui, Line::from(
            Span::styled(' LLM Requests', $h),
        ));
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        if ($requests->entries === []) {
            $rows[] = self::row($ui, Line::from(
                Span::styled('  (no requests yet)', $dim),
            ));

            return array_slice($rows, 0, $height);
        }

        foreach ($requests->entries as $i => $entry) {
            $isFocused = $i === $requests->focusedIndex;
            $rows[] = self::renderCard($ui, $entry, $isFocused);
        }

        return array_slice($rows, 0, $height);
    }

    private static function renderCard(Ui $ui, LlmRequestEntry $entry, bool $focused): Renderable
    {
        $statusStr = match (true) {
            $entry->error !== null => 'ERR',
            $entry->status !== null => (string) $entry->status,
            default => '...',
        };

        $timeStr = $entry->elapsedMs !== null ? number_format($entry->elapsedMs / 1000, 1) . 's' : '...';
        $tokStr = $entry->tokenCount !== null ? $entry->tokenCount . ' tok' : '';

        $parts = array_filter(["{$statusStr}", $timeStr, $tokStr], static fn(string $v): bool => $v !== '');
        $detail = implode(" \u{2022} ", $parts);

        $statusColor = match (true) {
            $entry->error !== null => Color::indexed(245),
            $entry->status === 200 => Color::indexed(250),
            !$entry->complete => Color::indexed(248),
            default => Color::indexed(250),
        };

        $prefix = $focused ? "\u{25b6} " : '  ';
        $line = Line::from(
            Span::styled($prefix, TextStyle::new()->fg($focused ? Color::indexed(252) : Color::indexed(242))),
            Span::styled($entry->method . ' ' . $entry->path . '  ', TextStyle::new()->fg(Color::indexed(252))),
            Span::styled($detail, TextStyle::new()->fg($statusColor)),
        );

        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }

    private static function kv(Ui $ui, string $key, TextStyle $keyStyle, string $value, TextStyle $valueStyle): Renderable
    {
        return self::row($ui, Line::from(
            Span::styled("    {$key}: ", $keyStyle),
            Span::styled($value, $valueStyle),
        ));
    }

    private static function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function opcacheStatus(): string
    {
        if (!extension_loaded('Zend OPcache') || !ini_get('opcache.enable_cli')) {
            return 'disabled (cli)';
        }

        $status = opcache_get_status(false);

        if ($status === false) {
            return 'unavailable';
        }

        $scripts = $status['opcache_statistics']['num_cached_scripts'] ?? 0;
        $hits = $status['opcache_statistics']['hits'] ?? 0;
        $misses = $status['opcache_statistics']['misses'] ?? 0;
        $memUsed = $status['memory_usage']['used_memory'] ?? 0;

        return "{$scripts} scripts, " . self::formatBytes($memUsed) . " used, {$hits}h/{$misses}m";
    }

    private static function keyExtensions(): string
    {
        $check = ['openswoole', 'pcntl', 'posix', 'sockets', 'mbstring', 'opcache'];
        $loaded = [];

        foreach ($check as $ext) {
            if (extension_loaded($ext)) {
                $loaded[] = $ext;
            }
        }

        return $loaded !== [] ? implode(', ', $loaded) : 'none detected';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}

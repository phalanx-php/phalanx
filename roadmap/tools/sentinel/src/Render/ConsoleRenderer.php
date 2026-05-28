<?php

declare(strict_types=1);

namespace Sentinel\Render;

use React\EventLoop\Loop;
use React\Stream\WritableResourceStream;
use Sentinel\SentinelConfig;
use Sentinel\Watcher\ChangeKind;
use Sentinel\Watcher\FileChange;

final class ConsoleRenderer implements ReviewRenderer
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";
    private const ITALIC = "\033[3m";

    private const FG_WHITE = "\033[97m";
    private const FG_GRAY = "\033[90m";
    private const FG_YELLOW = "\033[33m";
    private const FG_GREEN = "\033[32m";
    private const FG_RED = "\033[31m";
    private const FG_CYAN = "\033[36m";

    private const BG_NONE = '';
    private const INDENT = '    ';

    private const COLORS = [
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'red' => "\033[31m",
        'white' => "\033[97m",
    ];

    private const SEVERITY_COLORS = [
        'CRITICAL' => "\033[41m\033[97m",
        'HIGH' => "\033[31m",
        'MEDIUM' => "\033[33m",
        'LOW' => "\033[36m",
        'INFO' => "\033[90m",
    ];

    private float $startTime;

    private ?WritableResourceStream $logStream = null;

    private CodeBlockFormatter $codeFormatter;

    public function __construct(SentinelConfig $config)
    {
        $this->codeFormatter = new CodeBlockFormatter();
        $this->startTime = microtime(true);

        $h = @fopen($config->errorLog, 'a');
        if ($h !== false) {
            $this->logStream = new WritableResourceStream($h, Loop::get());
        }
    }

    public function banner(): void
    {
        $this->writeLine('');
        $this->writeLine(self::BOLD . self::FG_CYAN . '  SENTINEL' . self::RESET . self::DIM . ' -- Multi-Agent Code Review' . self::RESET);
        $this->writeLine(self::DIM . '  ' . str_repeat('-', 50) . self::RESET);
        $this->writeLine('');
    }

    public function agentRegistered(string $name, string $color): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $this->writeLine(self::DIM . "  + " . self::RESET . $c . $name . self::RESET . self::DIM . " registered" . self::RESET);
    }

    public function watchingDirectory(string $path): void
    {
        $this->writeLine(self::DIM . "  @ watching " . self::RESET . self::FG_WHITE . $path . self::RESET);
        $this->writeLine('');
    }

    public function ready(): void
    {
        $this->writeLine(self::FG_GREEN . "  Ready." . self::RESET . self::DIM . " Watching for changes. Type a message and press Enter." . self::RESET);
        $this->writeLine(self::DIM . '  ' . str_repeat('-', 50) . self::RESET);
        $this->writeLine(self::DIM
            . '  ctrl+c exit'
            . '  |  ctrl+w del word'
            . '  |  ctrl+u clear line'
            . '  |  opt+<> jump word'
            . '  |  up/dn history'
            . self::RESET);
        $this->writeLine('');
    }

    /**
     * @param list<FileChange> $changes
     */
    public function fileChanges(array $changes): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine('');
        $this->writeLine(self::DIM . "  [{$elapsed}]" . self::RESET . self::BOLD . self::FG_YELLOW . " FILE CHANGE" . self::RESET . self::DIM . " (" . count($changes) . " files)" . self::RESET);

        foreach ($changes as $change) {
            $kindColor = match ($change->kind) {
                ChangeKind::Created => self::FG_GREEN,
                ChangeKind::Modified => self::FG_YELLOW,
                ChangeKind::Deleted => self::FG_RED,
                ChangeKind::Renamed => self::FG_CYAN,
            };

            $kindLabel = match ($change->kind) {
                ChangeKind::Created => '+',
                ChangeKind::Modified => '~',
                ChangeKind::Deleted => '-',
                ChangeKind::Renamed => '>',
            };

            $this->writeLine("    " . $kindColor . $kindLabel . self::RESET . " " . self::FG_WHITE . $change->path . self::RESET);
        }

        $this->writeLine('');
    }

    public function agentFeedback(string $agentName, string $color, string $text): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $elapsed = $this->elapsed();

        $this->writeLine(self::DIM . "  [{$elapsed}] " . self::RESET . $c . self::BOLD . $agentName . self::RESET);

        $text = $this->codeFormatter->format($text);
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $formatted = $this->highlightSeverity($line);
            foreach (self::wordWrap($formatted, self::INDENT, $this->terminalWidth()) as $wrapped) {
                $this->writeLine($wrapped);
            }
        }

        $this->writeLine('');
    }

    public function agentToken(string $agentName, string $color, string $token): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $this->write($c . $token . self::RESET);
    }

    public function agentStreamStart(string $agentName, string $color): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $elapsed = $this->elapsed();
        $this->writeLine(self::DIM . "  [{$elapsed}] " . self::RESET . $c . self::BOLD . $agentName . self::RESET);
    }

    public function agentStreamEnd(): void
    {
        $this->writeLine('');
        $this->writeLine('');
    }

    public function humanMessage(string $message): void
    {
        $this->writeLine('');
    }

    public function externalMessage(string $from, string $message): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine('');
        $this->writeLine(self::DIM . "  [{$elapsed}] " . self::RESET . self::FG_CYAN . $from . self::RESET);

        foreach (self::wordWrap($message, self::INDENT, $this->terminalWidth()) as $line) {
            $this->writeLine($line);
        }

        $this->writeLine('');
    }

    public function reviewComplete(int $reviewNumber, ?float $elapsedSeconds = null, ?int $totalTokens = null): void
    {
        $parts = ["review #{$reviewNumber}"];

        if ($elapsedSeconds !== null) {
            $parts[] = sprintf('%.1fs', $elapsedSeconds);
        }

        if ($totalTokens !== null) {
            $parts[] = number_format($totalTokens) . ' tok';
        }

        $mem = memory_get_usage(true);
        $parts[] = $this->formatBytes($mem);

        $this->writeLine(self::DIM . '  --- ' . implode(' | ', $parts) . ' ---' . self::RESET);
        $this->writeLine('');
    }

    public function prompt(): void
    {
        $this->write(self::FG_CYAN . '  +> ' . self::RESET);
    }

    public function toolActivity(string $agentName, string $color, string $toolName, string $status, ?float $elapsedMs = null): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $label = str_replace('_', ' ', $toolName);

        if ($status === 'running') {
            $this->write(self::DIM . " [{$label}]" . self::RESET);
        } else {
            $ms = $elapsedMs !== null ? number_format($elapsedMs, 1) . 'ms' : '';
            $this->write(self::DIM . " [{$label} {$ms}]" . self::RESET);
        }
    }

    public function status(): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine(self::DIM . "  [{$elapsed}] Sentinel running. Agents active. Type 'exit' to stop." . self::RESET);
    }

    public function info(string $message): void
    {
        $this->writeLine(self::DIM . "  " . self::RESET . $message);
    }

    public function error(string $message): void
    {
        $this->writeLine(self::FG_RED . "  [error] " . self::RESET . $message);
        $this->logStream?->write('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
    }

    public function shutdown(): void
    {
        $this->writeLine('');
        $this->writeLine(self::DIM . "  Sentinel stopped." . self::RESET);
        $this->writeLine('');
    }

    private function highlightSeverity(string $line): string
    {
        foreach (self::SEVERITY_COLORS as $label => $color) {
            if (str_contains($line, "[{$label}]")) {
                return str_replace("[{$label}]", $color . "[{$label}]" . self::RESET, $line);
            }
        }

        return $line;
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

    private function terminalWidth(): int
    {
        static $width = null;

        if ($width !== null) {
            return $width;
        }

        $cols = 120;
        $stty = @exec('stty size 2>/dev/null');
        if ($stty !== '' && $stty !== false && preg_match('/\d+ (\d+)/', $stty, $m)) {
            $cols = (int) $m[1];
        }

        return $width = $cols;
    }

    /**
     * @return list<string>
     */
    private static function wordWrap(string $text, string $indent, int $terminalWidth): array
    {
        if ($text === '') {
            return [$indent];
        }

        $maxWidth = $terminalWidth - mb_strlen($indent);
        if ($maxWidth < 20) {
            return [$indent . $text];
        }

        $plainLength = mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $text));
        if ($plainLength <= $maxWidth) {
            return [$indent . $text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        $currentPlain = 0;

        foreach ($words as $word) {
            $wordPlain = mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $word));

            if ($currentPlain > 0 && ($currentPlain + 1 + $wordPlain) > $maxWidth) {
                $lines[] = $indent . $current;
                $current = $word;
                $currentPlain = $wordPlain;
            } else {
                $current = $currentPlain > 0 ? $current . ' ' . $word : $word;
                $currentPlain += ($currentPlain > 0 ? 1 : 0) + $wordPlain;
            }
        }

        if ($current !== '') {
            $lines[] = $indent . $current;
        }

        return $lines;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.0f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    private function writeLine(string $text): void
    {
        fwrite(STDOUT, $text . "\r\n");
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }
}
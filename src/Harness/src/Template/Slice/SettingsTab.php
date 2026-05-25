<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

enum SettingsTab: string
{
    case General = 'General';
    case Tools = 'Tools';
    case Mcp = 'MCP';
    case Model = 'Model';
    case Display = 'Display';

    /**
     * @return list<array{string, 'toggle'|'info', bool}>
     */
    public function items(?string $modelName = null): array
    {
        return match ($this) {
            self::General => [
                ['Line numbers in code blocks', 'toggle', false],
                ['Syntax highlighting', 'toggle', true],
                ['Show thinking content', 'toggle', false],
                ['Compact history panels', 'toggle', true],
            ],
            self::Tools => [
                ['Enable tool calls', 'toggle', true],
                ['Auto-expand results', 'toggle', false],
                ['Show arguments', 'toggle', true],
            ],
            self::Mcp => [
                ['MCP servers: (none configured)', 'info', false],
            ],
            self::Model => [
                ['Provider: Athena', 'info', false],
                ['Model: ' . ($modelName ?? 'none'), 'info', false],
                ['Temperature: default', 'info', false],
            ],
            self::Display => [
                ['Dark theme', 'toggle', true],
                ['Show status bar', 'toggle', true],
                ['Wrap long lines', 'toggle', true],
                ['Max history panels: 50', 'info', false],
            ],
        };
    }
}

<?php

declare(strict_types=1);

namespace Sentinel;

use Sentinel\Render\ConsoleRenderer;

final class PersonaPreset
{
    /** @var array<string, list<string>> preset name => persona filenames (without .md) */
    private const PRESETS = [
        'php' => ['architecture', 'performance', 'security', 'phalanx'],
        'react-native' => ['architecture', 'state', 'performance', 'security'],
        'tv' => ['navigation', 'streaming', 'state', 'performance'],
        'core' => ['architecture', 'security', 'performance'],
        'full' => ['architecture', 'performance', 'phalanx', 'state', 'security', 'navigation', 'streaming'],
    ];

    /** @return list<string>|null */
    public static function get(string $name): ?array
    {
        return self::PRESETS[$name] ?? null;
    }

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return self::PRESETS;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function printAll(ConsoleRenderer $renderer): void
    {
        $renderer->info('Available presets:');

        foreach (self::PRESETS as $name => $personas) {
            $list = implode(', ', $personas);
            $renderer->info("  {$name}  --  {$list}");
        }
    }
}

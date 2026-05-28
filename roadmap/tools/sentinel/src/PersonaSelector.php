<?php

declare(strict_types=1);

namespace Sentinel;

use Sentinel\Render\ConsoleRenderer;

final class PersonaSelector
{
    /**
     * @return list<string> Selected persona filenames (without .md)
     */
    public static function interactive(string $dossierDir, ConsoleRenderer $renderer): array
    {
        $available = self::scanPersonas($dossierDir);

        if ($available === []) {
            return [];
        }

        $renderer->info('Available agents:');
        $renderer->info('');

        $index = 1;
        $indexMap = [];
        foreach ($available as $lens => $persona) {
            $glyph = ":{$persona['name']}.{$lens}>";
            $renderer->info("  {$index}) {$glyph}  {$persona['tagline']}");
            $indexMap[$index] = $lens;
            $index++;
        }

        $renderer->info('');

        $presetHints = [];
        $lensKeys = array_keys($available);
        foreach (PersonaPreset::all() as $presetName => $presetFiles) {
            $nums = [];
            foreach ($presetFiles as $file) {
                $num = array_search($file, $lensKeys, true);
                if ($num !== false) {
                    $nums[] = $num + 1;
                }
            }
            if ($nums !== []) {
                $presetHints[] = "{$presetName} (" . implode(',', $nums) . ')';
            }
        }

        $renderer->info('Presets: ' . implode(' | ', $presetHints));
        $renderer->info('');

        fwrite(STDOUT, "  Select agents [numbers, preset name, or Enter for all]: ");
        $line = trim((string) fgets(STDIN));

        if ($line === '') {
            return $lensKeys;
        }

        $preset = PersonaPreset::get($line);
        if ($preset !== null) {
            return $preset;
        }

        if (preg_match('/^[\d,\s]+$/', $line)) {
            $nums = array_map(intval(...), preg_split('/[\s,]+/', $line));
            $selected = [];
            foreach ($nums as $n) {
                if (isset($indexMap[$n])) {
                    $selected[] = $indexMap[$n];
                }
            }
            return $selected;
        }

        $renderer->error("Unknown selection: {$line}. Using all.");
        return $lensKeys;
    }

    public static function printAvailable(string $dossierDir, ConsoleRenderer $renderer): void
    {
        $available = self::scanPersonas($dossierDir);

        $renderer->info('Available agents:');
        foreach ($available as $lens => $persona) {
            $glyph = ":{$persona['name']}.{$lens}>";
            $renderer->info("  {$glyph}  {$persona['tagline']}");
        }

        $renderer->info('');
        $renderer->info("Add custom agents to: {$dossierDir}/");
    }

    /**
     * @return array<string, array{name: string, tagline: string}> lens => persona info
     */
    private static function scanPersonas(string $dossierDir): array
    {
        $files = glob(rtrim($dossierDir, '/') . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $personas = [];
        foreach ($files as $file) {
            $lens = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            $name = preg_match('/^#\s+(.+)$/m', $content, $m) ? trim($m[1]) : ucfirst($lens);
            $tagline = preg_match('/^>\s*(.+)$/m', $content, $t) ? trim($t[1]) : '';
            $personas[$lens] = ['name' => $name, 'tagline' => $tagline];
        }

        return $personas;
    }
}

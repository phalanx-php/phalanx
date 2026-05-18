<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\ClaudeCode;

use Phalanx\Panoply\HomeDir\AbstractMapSettings;

/**
 * Settings accessor for Claude Code's merged configuration. Reads both a
 * sidecar file (`~/.claude.json`, when present) and an in-directory settings
 * file (`~/.claude/settings.json`, when present), merging them with the
 * in-directory file winning on key conflicts (deep merge — sub-objects are
 * merged key-by-key rather than replaced wholesale).
 *
 * Construction reads and parses both files immediately; subsequent accessor
 * calls are pure lookups into the merged map (via {@see AbstractMapSettings}).
 *
 * Malformed JSON causes a {@see \JsonException} to propagate — fail-loud
 * is the Phalanx default. An absent or empty file degrades silently to an
 * empty map, which is appropriate (the file is optional).
 *
 * Final — Settings implementations are sealed per vendor.
 */
final class Settings extends AbstractMapSettings
{
    public function __construct(
        private(set) ?string $sidecarPath,
        private(set) ?string $inDirPath,
    ) {
        $sidecar = self::readJson($sidecarPath);
        $inDir   = self::readJson($inDirPath);

        parent::__construct(self::deepMerge($sidecar, $inDir));
    }

    /**
     * Deep-merge two maps. Keys in `$override` win on conflict; sub-arrays
     * are merged recursively rather than replaced wholesale.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (
                isset($result[$key])
                && is_array($result[$key])
                && is_array($value)
            ) {
                /** @var array<string, mixed> $result[$key] */
                /** @var array<string, mixed> $value */
                $result[$key] = self::deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(?string $path): array
    {
        if ($path === null || !is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}

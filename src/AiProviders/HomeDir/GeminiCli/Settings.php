<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\GeminiCli;

use Phalanx\AiProviders\HomeDir\AbstractMapSettings;

/**
 * Settings accessor for Gemini CLI's single settings file
 * (`~/.gemini/settings.json`). There is no sidecar concept in Gemini CLI;
 * a single JSON file holds all configuration.
 *
 * Construction reads and parses the file immediately; subsequent accessor
 * calls are pure lookups (via {@see AbstractMapSettings}).
 *
 * Malformed JSON causes a {@see \JsonException} to propagate — fail-loud
 * is the Phalanx default. An absent or empty file degrades silently to an
 * empty map, which is appropriate (the file is optional).
 *
 * Final — Settings implementations are sealed per vendor.
 */
final class Settings extends AbstractMapSettings
{
    public function __construct(private(set) ?string $settingsPath)
    {
        parent::__construct(self::readJson($settingsPath));
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

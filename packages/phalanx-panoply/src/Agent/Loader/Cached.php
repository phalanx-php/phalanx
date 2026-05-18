<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Loader;
use Phalanx\Panoply\Agent\Loader\Support\Mtime;
use Phalanx\Panoply\Agent\Registry;

/**
 * Loader backed by a pre-built JSON cache file produced by the
 * `panoply:agents:scan` Archon command. Reading a cache is faster than a
 * full directory scan and requires no YAML parsing.
 *
 * Cache file format (JSON):
 * ```json
 * {
 *   "agents": ["App\\Agents\\HopliteAgent", "App\\Agents\\MarathonAgent"],
 *   "generated_at": "2026-05-17T00:00:00+00:00",
 *   "source_mtime": 1747440000
 * }
 * ```
 *
 * `source_mtime` is the maximum `filemtime()` across all PHP files in the
 * source directory at the time the scan was run. Use {@see self::isStale()}
 * to check whether the source directory has changed since the cache was built.
 *
 * Throws {@see LoaderError} on missing file, malformed JSON, or missing /
 * invalid required keys.
 *
 * Final — sealed cache-read contract.
 */
final class Cached implements Loader
{
    public function __construct(
        private(set) string $cachePath,
    ) {
    }

    public function load(): Registry
    {
        if (!is_file($this->cachePath)) {
            throw LoaderError::cacheNotFound($this->cachePath);
        }

        $raw = file_get_contents($this->cachePath);

        if ($raw === false || $raw === '') {
            throw LoaderError::cacheMalformed($this->cachePath, 'file is empty or unreadable');
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw LoaderError::cacheMalformed($this->cachePath, $e->getMessage());
        }

        self::assertStructure($payload, $this->cachePath);

        /** @var array{agents: list<string>, generated_at: string, source_mtime: int} $payload */
        $registry = Registry::empty();

        foreach ($payload['agents'] as $fqcn) {
            if (!is_string($fqcn) || $fqcn === '') {
                throw LoaderError::cacheMalformed($this->cachePath, "agents entry must be a non-empty string");
            }

            if (!class_exists($fqcn)) {
                throw LoaderError::notInstantiable($fqcn, "class not found (referenced in cache {$this->cachePath})");
            }

            $reflection = new \ReflectionClass($fqcn);

            if (!$reflection->implementsInterface(Agent::class)) {
                throw LoaderError::notAnAgent($fqcn);
            }

            if (!$reflection->isInstantiable()) {
                throw LoaderError::notInstantiable($fqcn);
            }

            /** @var Agent $instance */
            $instance = $reflection->newInstance();
            $registry = $registry->with($instance);
        }

        return $registry;
    }

    /**
     * Returns true when the cache is older than the newest PHP file in
     * `$sourceDirectory`. The check walks the directory using SPL iterators
     * and takes the maximum `filemtime()` across all `*.php` files.
     *
     * A missing cache file is always considered stale.
     */
    public function isStale(string $sourceDirectory): bool
    {
        if (!is_file($this->cachePath)) {
            return true;
        }

        $raw = file_get_contents($this->cachePath);

        if ($raw === false || $raw === '') {
            return true;
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }

        if (!is_array($payload) || !isset($payload['source_mtime']) || !is_int($payload['source_mtime'])) {
            return true;
        }

        $cachedMtime = $payload['source_mtime'];
        $sourceMtime = Mtime::maxIn($sourceDirectory);

        return $sourceMtime > $cachedMtime;
    }

    /**
     * @param mixed $payload
     */
    private static function assertStructure(mixed $payload, string $path): void
    {
        if (!is_array($payload)) {
            throw LoaderError::cacheMalformed($path, 'root must be a JSON object');
        }

        foreach (['agents', 'generated_at', 'source_mtime'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw LoaderError::cacheMalformed($path, "missing required key '{$key}'");
            }
        }

        if (!is_array($payload['agents'])) {
            throw LoaderError::cacheMalformed($path, "'agents' must be an array");
        }

        if (!is_string($payload['generated_at']) || $payload['generated_at'] === '') {
            throw LoaderError::cacheMalformed($path, "'generated_at' must be a non-empty string");
        }

        if (!is_int($payload['source_mtime'])) {
            throw LoaderError::cacheMalformed($path, "'source_mtime' must be an integer");
        }
    }
}

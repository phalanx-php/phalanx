<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Archon;

use Phalanx\Panoply\Agent\Loader\Attribute as AttributeLoader;
use Phalanx\Panoply\Agent\Loader\Support\Mtime;
// This first-party Archon adapter is limited to Scope and Task imports.
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

/**
 * Archon command that scans a PSR-4 namespace directory for
 * {@see \Phalanx\Panoply\Agent\Discovered}-annotated classes and writes a
 * JSON cache file consumable by {@see \Phalanx\Panoply\Agent\Loader\Cached}.
 *
 * This is the SECOND documented boundary exception in phalanx-panoply (after
 * `Runtime/Aegis/Runtime.php`). It imports `Phalanx\Scope\Scope` and
 * `Phalanx\Task\Scopeable` to participate in the Archon runtime as a supervised
 * task. The boundary is narrow and intentional — no further Phalanx-package
 * imports are permitted in this class.
 *
 * Register with Archon via:
 * ```php
 * $archon->command('panoply:agents:scan', PanoplyAgentsScanCommand::class, $config);
 * ```
 * Panoply does NOT auto-wire this command. The host application is responsible
 * for instantiating and registering it.
 *
 * Cache file format (JSON):
 * ```json
 * {
 *   "agents": ["App\\Agents\\HopliteAgent", "..."],
 *   "generated_at": "ISO-8601 timestamp",
 *   "source_mtime": <epoch int>
 * }
 * ```
 *
 * `source_mtime` is the maximum `filemtime()` across all `*.php` files found
 * recursively under `$sourceDirectory`. The command skips regeneration when
 * the existing cache `generated_at` is newer than the source directory's
 * maximum `*.php` mtime — making repeated invocations safe and idempotent.
 *
 * Final — sealed command contract.
 */
final class PanoplyAgentsScanCommand implements Scopeable
{
    public function __construct(
        private(set) string $sourceDirectory,
        private(set) string $namespacePrefix,
        private(set) string $cacheOutputPath,
    ) {
    }

    /**
     * Scan the source directory, derive agent FQCNs, and write a JSON cache.
     *
     * Returns 0 on success (both "regenerated" and "already fresh" paths).
     */
    public function __invoke(Scope $scope): int
    {
        $sourceMtime = Mtime::maxIn($this->sourceDirectory);

        if (self::isCacheFresh($this->cacheOutputPath, $sourceMtime)) {
            return 0;
        }

        $registry = new AttributeLoader($this->sourceDirectory, $this->namespacePrefix)->load();
        $payload = self::buildPayload($registry, $sourceMtime);

        self::writeCache($this->cacheOutputPath, $payload);

        return 0;
    }

    /**
     * Build the JSON payload array from a loaded Registry and a source mtime.
     *
     * @return array{agents: list<string>, generated_at: string, source_mtime: int}
     */
    private static function buildPayload(\Phalanx\Panoply\Agent\Registry $registry, int $sourceMtime): array
    {
        $fqcns = array_map(
            static fn (\Phalanx\Panoply\Agent $agent): string => $agent::class,
            $registry->all()->toArray(),
        );

        return [
            'agents' => array_values($fqcns),
            'generated_at' => gmdate('c'),
            'source_mtime' => $sourceMtime,
        ];
    }

    /**
     * Encode `$payload` as JSON and write it to `$path`, creating parent
     * directories as needed.
     *
     * @param array{agents: list<string>, generated_at: string, source_mtime: int} $payload
     */
    private static function writeCache(string $path, array $payload): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache directory: {$dir}");
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $result = file_put_contents($path, $json);

        if ($result === false) {
            throw new \RuntimeException("Cannot write cache file: {$path}");
        }
    }

    /**
     * Returns true when the existing cache file's `generated_at` timestamp is
     * more recent than the source directory's maximum PHP file mtime.
     */
    private static function isCacheFresh(string $cachePath, int $sourceMtime): bool
    {
        if (!is_file($cachePath)) {
            return false;
        }

        $raw = file_get_contents($cachePath);

        if ($raw === false || $raw === '') {
            return false;
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($payload) || !isset($payload['source_mtime']) || !is_int($payload['source_mtime'])) {
            return false;
        }

        // Cache is fresh when its recorded source_mtime is >= the current source mtime.
        return $payload['source_mtime'] >= $sourceMtime;
    }
}

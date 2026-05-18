<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex;

use Phalanx\Panoply\Conversation\Parser as ParserInterface;
use Phalanx\Panoply\HomeDir as HomeDirInterface;
use Phalanx\Panoply\HomeDir\AdapterFactory;
use Phalanx\Panoply\HomeDir\Config;
use Phalanx\Panoply\HomeDir\Locator;
use Phalanx\Panoply\HomeDir\Locators;
use Phalanx\Panoply\HomeDir\Project;
use Phalanx\Panoply\HomeDir\Projects;
use Phalanx\Panoply\HomeDir\Settings as SettingsInterface;

/**
 * Read-only adapter for Codex's `~/.codex/` home directory. Codex records
 * conversations in three overlapping sources:
 *
 * 1. `sessions/<year>/<date>/*.jsonl` — per-session JSONL files organised
 *    in a year/date directory tree.
 * 2. `history.jsonl` — a rolling aggregate of all sessions in one file.
 * 3. `logs_2.sqlite` — a SQLite database mirror of the same events (requires
 *    `ext-sqlite3`).
 *
 * Projects are derived by scanning the sessions tree and collecting distinct
 * `cwd` values from the first line of each session JSONL file. Each unique
 * cwd becomes a {@see Project}.
 *
 * Final — HomeDir adapters are sealed per vendor.
 */
final class HomeDir implements HomeDirInterface, AdapterFactory
{
    public function __construct(
        private(set) string $homeDirPath,
    ) {
    }

    public static function fromConfig(Config $config, string $home): self
    {
        $roots       = $config->roots;
        $homeDirPath = Config::resolvePath($roots[0] ?? '~/.codex', $home);

        return new self($homeDirPath);
    }

    public function projects(): Projects
    {
        $homeDirPath = $this->homeDirPath;

        return new Projects(static function () use ($homeDirPath): \Generator {
            $sessionsDir = $homeDirPath . '/sessions';

            if (!is_dir($sessionsDir)) {
                return;
            }

            $outerIter = new \RecursiveDirectoryIterator(
                $sessionsDir,
                \FilesystemIterator::SKIP_DOTS,
            );
            $innerIter = new \RecursiveIteratorIterator($outerIter);

            /** @var array<string, array{lastActive: ?\DateTimeImmutable, count: int}> $cwdMap */
            $cwdMap = [];

            /** @var \SplFileInfo $file */
            foreach ($innerIter as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'jsonl') {
                    continue;
                }

                // Read only the first line to extract the cwd.
                $fileObj = new \SplFileObject($file->getPathname(), 'r');
                $fileObj->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);
                $firstLine = $fileObj->fgets();
                unset($fileObj);

                if ($firstLine === false || $firstLine === '') {
                    continue;
                }

                $row = json_decode(trim($firstLine), associative: true);
                $cwd = is_array($row) && isset($row['cwd']) && is_string($row['cwd'])
                    ? $row['cwd']
                    : '';

                if ($cwd === '') {
                    // Fall back to the session path as the project identity.
                    $cwd = dirname($file->getPathname());
                }

                $mtime      = $file->getMTime();
                $lastActive = new \DateTimeImmutable()->setTimestamp($mtime);

                if (!isset($cwdMap[$cwd])) {
                    $cwdMap[$cwd] = ['lastActive' => $lastActive, 'count' => 0];
                }

                $cwdMap[$cwd]['count']++;

                if ($cwdMap[$cwd]['lastActive'] === null || $mtime > $cwdMap[$cwd]['lastActive']->getTimestamp()) {
                    $cwdMap[$cwd]['lastActive'] = $lastActive;
                }
            }

            foreach ($cwdMap as $cwd => $meta) {
                yield new Project(
                    slug: md5($cwd),
                    path: $cwd,
                    homeDirId: 'codex',
                    lastActive: $meta['lastActive'],
                    conversationCount: $meta['count'],
                );
            }
        });
    }

    public function locators(): Locators
    {
        $homeDirPath = $this->homeDirPath;

        return new Locators(static function () use ($homeDirPath): \Generator {
            $sessionsDir = $homeDirPath . '/sessions';

            if (is_dir($sessionsDir)) {
                yield new Locator(path: $sessionsDir, isDirectory: true);
            }

            $historyPath = $homeDirPath . '/history.jsonl';

            if (is_file($historyPath)) {
                $stat = stat($historyPath);
                yield new Locator(
                    path: $historyPath,
                    isDirectory: false,
                    sizeBytes: $stat !== false ? (int) $stat['size'] : null,
                    modifiedAt: $stat !== false
                        ? new \DateTimeImmutable()->setTimestamp((int) $stat['mtime'])
                        : null,
                );
            }

            $sqlitePath = $homeDirPath . '/logs_2.sqlite';

            if (is_file($sqlitePath)) {
                $stat = stat($sqlitePath);
                yield new Locator(
                    path: $sqlitePath,
                    isDirectory: false,
                    sizeBytes: $stat !== false ? (int) $stat['size'] : null,
                    modifiedAt: $stat !== false
                        ? new \DateTimeImmutable()->setTimestamp((int) $stat['mtime'])
                        : null,
                );
            }
        });
    }

    public function parser(): ParserInterface
    {
        return new Parser();
    }

    public function settings(): SettingsInterface
    {
        $configPath = $this->homeDirPath . '/config.toml';

        return new Settings(
            configTomlPath: is_file($configPath) ? $configPath : null,
        );
    }
}

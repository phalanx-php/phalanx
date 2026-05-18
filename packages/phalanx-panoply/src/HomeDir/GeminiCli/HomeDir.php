<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\GeminiCli;

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
 * Read-only adapter for Gemini CLI's `~/.gemini/` home directory. Surfaces
 * per-project conversations from the `projects.json` index, the tool's
 * filesystem locations, a normalizing conversation parser, and the Gemini
 * settings.
 *
 * Gemini CLI stores a `projects.json` array in the home directory. Each
 * entry carries `path`, `name`, and `lastActive` fields. Per-project
 * conversation history lives under `history/<project_id>/*.jsonl`.
 *
 * There is no sidecar concept in Gemini CLI — all settings live in
 * `~/.gemini/settings.json`.
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
        $homeDirPath = Config::resolvePath($roots[0] ?? '~/.gemini', $home);

        return new self($homeDirPath);
    }

    public function projects(): Projects
    {
        $homeDirPath = $this->homeDirPath;

        return new Projects(static function () use ($homeDirPath): \Generator {
            $projectsFile = $homeDirPath . '/projects.json';

            if (!is_file($projectsFile)) {
                return;
            }

            $raw = file_get_contents($projectsFile);

            if ($raw === false) {
                return;
            }

            $entries = json_decode($raw, associative: true);

            if (!is_array($entries)) {
                return;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $path       = isset($entry['path']) && is_string($entry['path']) ? $entry['path'] : '';
                $name       = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : $path;
                $projectId  = isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : md5($path);
                $lastActive = null;

                if (isset($entry['lastActive']) && is_string($entry['lastActive'])) {
                    $lastActiveRaw = $entry['lastActive'];
                    $dt            = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $lastActiveRaw)
                        ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $lastActiveRaw)
                        ?: null;
                    $lastActive    = $dt;
                }

                // Count JSONL files in history/<project_id>/.
                $historyDir        = $homeDirPath . '/history/' . $projectId;
                $conversationCount = 0;

                if (is_dir($historyDir)) {
                    $histIter = new \DirectoryIterator($historyDir);
                    foreach ($histIter as $file) {
                        if ($file->isFile() && $file->getExtension() === 'jsonl') {
                            $conversationCount++;
                        }
                    }
                }

                yield new Project(
                    slug: $projectId,
                    path: $path,
                    homeDirId: 'gemini_cli',
                    lastActive: $lastActive,
                    conversationCount: $conversationCount,
                    metadata: ['name' => $name],
                );
            }
        });
    }

    public function locators(): Locators
    {
        $homeDirPath = $this->homeDirPath;

        return new Locators(static function () use ($homeDirPath): \Generator {
            if (is_dir($homeDirPath)) {
                $stat = stat($homeDirPath);
                yield new Locator(
                    path: $homeDirPath,
                    isDirectory: true,
                    sizeBytes: null,
                    modifiedAt: $stat !== false
                        ? new \DateTimeImmutable()->setTimestamp((int) $stat['mtime'])
                        : null,
                );
            }

            $historyDir = $homeDirPath . '/history';

            if (is_dir($historyDir)) {
                $stat = stat($historyDir);
                yield new Locator(
                    path: $historyDir,
                    isDirectory: true,
                    sizeBytes: null,
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
        $settingsPath = $this->homeDirPath . '/settings.json';

        return new Settings(
            settingsPath: is_file($settingsPath) ? $settingsPath : null,
        );
    }
}

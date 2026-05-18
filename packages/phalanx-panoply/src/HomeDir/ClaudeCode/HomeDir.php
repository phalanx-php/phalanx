<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\ClaudeCode;

use Phalanx\Panoply\Conversation\Parser as ParserInterface;
use Phalanx\Panoply\HomeDir as HomeDirInterface;
use Phalanx\Panoply\HomeDir\AdapterFactory;
use Phalanx\Panoply\HomeDir\Config;
use Phalanx\Panoply\HomeDir\Locator;
use Phalanx\Panoply\HomeDir\Locators;
use Phalanx\Panoply\HomeDir\Project;
use Phalanx\Panoply\HomeDir\Projects;
use Phalanx\Panoply\HomeDir\Settings as SettingsInterface;
use Phalanx\Panoply\HomeDir\Slug;

/**
 * Read-only adapter for Claude Code's `~/.claude/` home directory. Surfaces
 * per-project conversations, the tools known filesystem locations, a
 * normalizing conversation parser, and the merged Claude settings.
 *
 * The `projects/` subdirectory contains one folder per visited cwd; each
 * folder name is the cwd encoded as a Claude Code path-slug (every `/`
 * becomes `-`). Conversations live as `*.jsonl` files within each project
 * folder.
 *
 * The optional sidecar (`~/.claude.json` by convention) carries account-level
 * settings. The in-directory `settings.json` carries workspace-level overrides.
 * When both are present the in-directory file wins on conflict (deep merge).
 *
 * Final — HomeDir adapters are sealed per vendor.
 */
final class HomeDir implements HomeDirInterface, AdapterFactory
{
    public function __construct(
        private(set) string $homeDirPath,
        private(set) ?string $sidecarPath = null,
    ) {
    }

    public static function fromConfig(Config $config, string $home): self
    {
        // roots[0] is the homeDirPath; roots[1] (when present) is the sidecar.
        $roots       = $config->roots;
        $homeDirPath = Config::resolvePath($roots[0] ?? '~/.claude', $home);
        $sidecarPath = isset($roots[1]) ? Config::resolvePath($roots[1], $home) : null;

        return new self($homeDirPath, $sidecarPath);
    }

    public function projects(): Projects
    {
        $homeDirPath = $this->homeDirPath;

        return new Projects(static function () use ($homeDirPath): \Generator {
            $projectsDir = $homeDirPath . '/projects';

            if (!is_dir($projectsDir)) {
                return;
            }

            $iter = new \DirectoryIterator($projectsDir);

            foreach ($iter as $entry) {
                if ($entry->isDot() || !$entry->isDir()) {
                    continue;
                }

                $slug = $entry->getFilename();
                $path = Slug::decode($slug);
                $dir  = $entry->getPathname();

                $conversationCount = 0;
                $latestMtime       = null;

                $innerIter = new \DirectoryIterator($dir);
                foreach ($innerIter as $file) {
                    if ($file->isDot() || !$file->isFile()) {
                        continue;
                    }
                    if ($file->getExtension() === 'jsonl') {
                        $conversationCount++;
                        $mtime = $file->getMTime();
                        if ($latestMtime === null || $mtime > $latestMtime) {
                            $latestMtime = $mtime;
                        }
                    }
                }

                $lastActive = $latestMtime !== null
                    ? new \DateTimeImmutable('@' . (int) $latestMtime)
                    : null;

                yield new Project(
                    slug: $slug,
                    path: $path,
                    homeDirId: 'claude_code',
                    lastActive: $lastActive,
                    conversationCount: $conversationCount,
                );
            }
        });
    }

    public function locators(): Locators
    {
        $homeDirPath = $this->homeDirPath;
        $sidecarPath = $this->sidecarPath;

        return new Locators(static function () use ($homeDirPath, $sidecarPath): \Generator {
            if (is_dir($homeDirPath)) {
                $stat = stat($homeDirPath);
                yield new Locator(
                    path: $homeDirPath,
                    isDirectory: true,
                    sizeBytes: null,
                    modifiedAt: $stat !== false
                        ? new \DateTimeImmutable('@' . (int) $stat['mtime'])
                        : null,
                );
            }

            if ($sidecarPath !== null && is_file($sidecarPath)) {
                $stat = stat($sidecarPath);
                yield new Locator(
                    path: $sidecarPath,
                    isDirectory: false,
                    sizeBytes: $stat !== false ? (int) $stat['size'] : null,
                    modifiedAt: $stat !== false
                        ? new \DateTimeImmutable('@' . (int) $stat['mtime'])
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
        $inDirPath = $this->homeDirPath . '/settings.json';

        return new Settings(
            sidecarPath: $this->sidecarPath,
            inDirPath: is_file($inDirPath) ? $inDirPath : null,
        );
    }
}

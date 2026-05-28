<?php

declare(strict_types=1);

namespace Sentinel\Watcher;

use FilesystemIterator;
use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Emitter;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProjectWatcher
{
    private const IGNORE_PATTERNS = [
        'vendor', 'node_modules', '.git', '.idea', '.vscode',
        'storage', 'cache', '.php-cs-fixer.cache',
    ];

    public static function watch(string $projectRoot, float $debounceSeconds = 0.5): Emitter
    {
        return Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($projectRoot, $debounceSeconds): void {
            $projectRoot = rtrim($projectRoot, '/');
            $interval = max(0.25, $debounceSeconds);
            $snapshot = self::snapshot($projectRoot);
            $done = new Deferred();

            $timer = Loop::addPeriodicTimer($interval, static function () use (&$snapshot, $projectRoot, $ch): void {
                $next = self::snapshot($projectRoot);
                $changes = self::diffSnapshots($snapshot, $next);
                $snapshot = $next;

                if ($changes !== []) {
                    $ch->emit($changes);
                }
            });

            $ctx->onDispose(static function () use ($timer, $done): void {
                Loop::cancelTimer($timer);
                $done->resolve(null);
            });

            $ctx->await($done->promise());
        });
    }

    /**
     * @return array<string, array{path: string, fingerprint: string}>
     */
    private static function snapshot(string $projectRoot): array
    {
        if (!is_dir($projectRoot)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
        );

        $snapshot = [];

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativePath = substr($fullPath, strlen($projectRoot) + 1);

            if ($relativePath === false || self::shouldIgnore($relativePath) || !self::isCodePath($relativePath)) {
                continue;
            }

            $snapshot[$relativePath] = [
                'path' => $fullPath,
                'fingerprint' => self::fingerprint($file),
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array{path: string, fingerprint: string}> $before
     * @param array<string, array{path: string, fingerprint: string}> $after
     * @return list<FileChange>
     */
    private static function diffSnapshots(array $before, array $after): array
    {
        $changes = [];
        $timestamp = microtime(true);

        foreach ($after as $relativePath => $current) {
            $previous = $before[$relativePath] ?? null;

            if ($previous === null) {
                // Diff is computed lazily by the consumer fiber via RunCommand;
                // running `git diff` here would block the periodic-timer callback.
                $changes[] = new FileChange(
                    path: $relativePath,
                    kind: ChangeKind::Created,
                    timestamp: $timestamp,
                );
                continue;
            }

            if ($previous['fingerprint'] !== $current['fingerprint']) {
                $changes[] = new FileChange(
                    path: $relativePath,
                    kind: ChangeKind::Modified,
                    timestamp: $timestamp,
                );
            }
        }

        foreach ($before as $relativePath => $previous) {
            if (isset($after[$relativePath])) {
                continue;
            }

            $changes[] = new FileChange(
                path: $relativePath,
                kind: ChangeKind::Deleted,
                timestamp: $timestamp,
            );
        }

        return $changes;
    }

    private static function shouldIgnore(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);

        foreach ($segments as $segment) {
            if (in_array($segment, self::IGNORE_PATTERNS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isCodePath(string $relativePath): bool
    {
        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);

        return in_array($ext, ['php', 'ts', 'tsx', 'js', 'jsx', 'json', 'yaml', 'yml', 'neon', 'xml'], true);
    }

    private static function fingerprint(SplFileInfo $file): string
    {
        return implode(':', [
            (string) $file->getMTime(),
            (string) $file->getSize(),
        ]);
    }

}

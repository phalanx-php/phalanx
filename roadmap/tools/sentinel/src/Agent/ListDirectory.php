<?php

declare(strict_types=1);

namespace Sentinel\Agent;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ListDirectory implements Tool
{
    public string $description {
        get => 'List files and directories in a project path (max 2 levels deep)';
    }

    public function __construct(
        #[Param('Relative path from project root')]
        private string $path = '.',
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $projectRoot = $scope->attribute('sentinel.project_root');
        $fullPath = rtrim($projectRoot, '/') . '/' . ltrim($this->path, '/');
        $realPath = realpath($fullPath);

        if ($realPath === false || !str_starts_with($realPath, realpath($projectRoot))) {
            return ToolOutcome::data(['error' => 'Directory not found or outside project root']);
        }

        if (!is_dir($realPath)) {
            return ToolOutcome::data(['error' => 'Not a directory']);
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $iterator->setMaxDepth(2);

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative = str_replace($realPath . '/', '', $item->getPathname());

            if (str_starts_with($relative, '.') || str_contains($relative, '/vendor/') || str_contains($relative, '/node_modules/')) {
                continue;
            }

            $entries[] = [
                'path' => $relative,
                'type' => $item->isDir() ? 'dir' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
            ];
        }

        usort($entries, static fn(array $a, array $b) => strcmp($a['path'], $b['path']));

        return ToolOutcome::data([
            'directory' => $this->path,
            'entries' => array_slice($entries, 0, 100),
            'truncated' => count($entries) > 100,
        ]);
    }
}

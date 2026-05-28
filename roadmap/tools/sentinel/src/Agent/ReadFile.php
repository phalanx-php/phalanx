<?php

declare(strict_types=1);

namespace Sentinel\Agent;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\Files;
use Phalanx\Scope;

final class ReadFile implements Tool
{
    public string $description {
        get => 'Read the contents of a file in the project for additional context';
    }

    public function __construct(
        #[Param('Relative path from project root')]
        private string $path,
        #[Param('Starting line number (1-indexed, 0 for entire file)')]
        private int $startLine = 0,
        #[Param('Ending line number (0 for end of file)')]
        private int $endLine = 0,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $projectRoot = $scope->attribute('sentinel.project_root');
        $fullPath = rtrim($projectRoot, '/') . '/' . ltrim($this->path, '/');
        $realPath = realpath($fullPath);

        if ($realPath === false || !str_starts_with($realPath, realpath($projectRoot))) {
            return ToolOutcome::data(['error' => 'File not found or outside project root']);
        }

        $files = $scope->service(Files::class);

        try {
            $info = $files->stat($realPath);
        } catch (FilesystemException) {
            return ToolOutcome::data(['error' => 'File not readable']);
        }

        if ($info->size > 100_000) {
            return ToolOutcome::data(['error' => 'File too large (>100KB). Use startLine/endLine to read a section.']);
        }

        try {
            $content = $files->read($realPath);
        } catch (FilesystemException $e) {
            return ToolOutcome::data(['error' => 'Read failed: ' . $e->getMessage()]);
        }

        if ($this->startLine > 0) {
            $lines = explode("\n", $content);
            $start = max(0, $this->startLine - 1);
            $end = $this->endLine > 0 ? $this->endLine : count($lines);
            $content = implode("\n", array_slice($lines, $start, $end - $start));
        }

        return ToolOutcome::data([
            'path' => $this->path,
            'content' => $content,
            'lines' => substr_count($content, "\n") + 1,
        ]);
    }
}

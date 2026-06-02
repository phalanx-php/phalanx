<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Prompts;

use Phalanx\Grammata\NativeFastPath\NativeFastPath;
use Phalanx\Scope\TaskScope;

final class FilePrompt implements PromptSource
{
    private(set) string $path;

    /** computed: stable prompt-source identity for diagnostics and caching. */
    public string $id {
        get => 'file:' . $this->path;
    }

    public function __construct(
        string $path,
    ) {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Prompt file path cannot be empty.');
        }

        if (is_dir($path)) {
            throw new \InvalidArgumentException('Prompt file path must reference a file, not a directory.');
        }

        $this->path = $path;
    }

    public function __invoke(TaskScope $scope): string
    {
        $path = $this->path;

        return self::read($scope, $path);
    }

    private static function read(TaskScope $scope, string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Prompt file does not exist: {$path}");
        }

        if (!is_readable($path)) {
            throw new \RuntimeException("Prompt file is not readable: {$path}");
        }

        return (new NativeFastPath())->read($scope, $path);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerLoader;
use Phalanx\Scope\Scope;
use RuntimeException;

final class CommandLoader
{
    /**
     * Load commands from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function load(?Scope $scope, string $path): CommandGroup
    {
        $result = HandlerLoader::load($scope, $path);

        if ($result instanceof CommandGroup) {
            return $result;
        }

        if ($result instanceof HandlerGroup) {
            return CommandGroup::fromHandlerGroup($result);
        }

        throw new RuntimeException(
            "Expected CommandGroup or HandlerGroup, got: " . get_debug_type($result)
        );
    }

    /**
     * Load and merge all command files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    public static function loadDirectory(?Scope $scope, string $dir): CommandGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = CommandGroup::of([]);
        $files = [];
        foreach (new \GlobIterator($dir . '/*.php', \FilesystemIterator::SKIP_DOTS) as $file) {
            $files[] = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
        }
        sort($files);

        foreach ($files as $file) {
            $group = $group->merge(self::load($scope, $file));
        }

        return $group;
    }
}

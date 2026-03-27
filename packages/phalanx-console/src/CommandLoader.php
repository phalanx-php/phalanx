<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerLoader;
use Phalanx\Scope;
use RuntimeException;

final class CommandLoader
{
    /**
     * Load commands from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function load(string $path, ?Scope $scope = null): CommandGroup
    {
        $result = HandlerLoader::load($path, $scope);

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
    public static function loadDirectory(string $dir, ?Scope $scope = null): CommandGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = CommandGroup::create();
        $files = glob($dir . '/*.php');

        if ($files === false) {
            return $group;
        }

        sort($files);

        foreach ($files as $file) {
            $group = $group->merge(self::load($file, $scope));
        }

        return $group;
    }
}

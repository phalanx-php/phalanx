<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerLoader;
use Phalanx\Scope\Scope;
use RuntimeException;

final readonly class RouteLoader
{
    /**
     * Load routes from a single file.
     *
     * @param Scope|null $scope For dynamic loading via closure
     * @param string $path Path to PHP file
     */
    public static function load(?Scope $scope, string $path): RouteGroup
    {
        $result = HandlerLoader::load($scope, $path);

        if ($result instanceof RouteGroup) {
            return $result;
        }

        if ($result instanceof HandlerGroup) {
            return RouteGroup::fromHandlerGroup($result);
        }

        throw new RuntimeException(
            "Expected RouteGroup or HandlerGroup, got: " . get_debug_type($result)
        );
    }

    /**
     * Load and merge all route files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param Scope|null $scope For dynamic loading
     * @param string $dir Directory path
     */
    public static function loadDirectory(?Scope $scope, string $dir): RouteGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = RouteGroup::of([]);
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

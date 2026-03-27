<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerLoader;
use Phalanx\Scope;
use RuntimeException;

final readonly class RouteLoader
{
    /**
     * Load routes from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function load(string $path, ?Scope $scope = null): RouteGroup
    {
        $result = HandlerLoader::load($path, $scope);

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
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    public static function loadDirectory(string $dir, ?Scope $scope = null): RouteGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = RouteGroup::create();
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

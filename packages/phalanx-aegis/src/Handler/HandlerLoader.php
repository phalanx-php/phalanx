<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Closure;
use Phalanx\Scope;
use RuntimeException;

/**
 * File-based handler discovery.
 *
 * Handler files return either:
 * - HandlerGroup (or protocol-specific group) directly
 * - Closure(Scope): group for dynamic loading
 *
 * Protocol-specific loaders (RouteLoader, CommandLoader) wrap this
 * for typed loading.
 */
final readonly class HandlerLoader
{
    /**
     * Load handlers from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function load(string $path, ?Scope $scope = null): mixed
    {
        if (!is_file($path)) {
            throw new RuntimeException("Handler file not found: $path");
        }

        $result = require $path;

        if ($result instanceof HandlerGroup) {
            return $result;
        }

        if (is_object($result) && !$result instanceof Closure) {
            return $result;
        }

        if ($result instanceof Closure) {
            if ($scope === null) {
                throw new RuntimeException(
                    "Handler file returns closure but no scope provided: $path"
                );
            }

            $group = $result($scope);

            if (!is_object($group)) {
                throw new RuntimeException(
                    "Handler closure must return a group object, got: "
                    . get_debug_type($group)
                );
            }

            return $group;
        }

        throw new RuntimeException(
            "Handler file must return a group or Closure, got: "
            . get_debug_type($result)
        );
    }

    /**
     * Load and merge all handler files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    public static function loadDirectory(string $dir, ?Scope $scope = null): HandlerGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = HandlerGroup::create();
        $files = glob($dir . '/*.php');

        if ($files === false) {
            return $group;
        }

        sort($files);

        foreach ($files as $file) {
            $result = self::load($file, $scope);

            if ($result instanceof HandlerGroup) {
                $group = $group->merge($result);
            }
        }

        return $group;
    }
}

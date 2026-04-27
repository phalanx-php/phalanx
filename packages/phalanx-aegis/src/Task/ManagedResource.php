<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Closure;
use Phalanx\Support\ErrorHandler;
use ReflectionClass;
use WeakMap;

/**
 * Wraps resources with cleanup callbacks tracked via WeakMap.
 *
 * WeakMap semantics: if the proxy is garbage collected before shutdown,
 * its cleanup callback is also removed. This is intentional - cleanup
 * only runs for resources still referenced at shutdown time.
 *
 * Call enableShutdownFlush() during application startup to register
 * the shutdown handler. This is done automatically by Application::startup().
 */
final class ManagedResource
{
    /** @var WeakMap<object, Closure> */
    private static WeakMap $cleanup;
    private static bool $shutdownRegistered = false;

    /**
     * Wrap a resource with a cleanup callback.
     *
     * Returns a lazy proxy that delegates to the original resource.
     * The cleanup callback runs at shutdown if the proxy is still referenced.
     */
    public static function wrap(object $resource, Closure $onRelease): object
    {
        self::$cleanup ??= new WeakMap();

        $reflector = new ReflectionClass($resource::class);

        $proxy = $reflector->newLazyProxy(static fn() => $resource);

        self::$cleanup[$proxy] = $onRelease;

        return $proxy;
    }

    /**
     * Register shutdown handler to flush remaining cleanup callbacks.
     *
     * Safe to call multiple times - only registers once.
     * Called automatically by Application::startup().
     */
    public static function enableShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$cleanup ?? [] as $cleanup) {
                try {
                    $cleanup();
                } catch (\Throwable $e) {
                    ErrorHandler::report("ManagedResource cleanup failed: " . $e->getMessage());
                }
            }
        });
    }
}

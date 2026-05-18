<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader;

use FilesystemIterator;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Discovered;
use Phalanx\Panoply\Agent\Loader;
use Phalanx\Panoply\Agent\Registry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Loader that discovers Agent classes by walking a directory tree and
 * inspecting PHP files for the {@see Discovered} marker attribute.
 *
 * A class must satisfy three conditions to be registered:
 * 1. Carries `#[Discovered]` on the class declaration.
 * 2. Implements {@see Agent}.
 * 3. Has a no-argument (trivial) constructor so the loader can instantiate it.
 *
 * Violation of condition 2 throws {@see LoaderError::notAnAgent()}.
 * Violation of condition 3 throws {@see LoaderError::nonTrivialConstructor()} when
 * required parameters are detected. If instantiation fails for any other reason
 * (e.g., an `ArgumentCountError` from missing optional-but-still-declared parameters),
 * PHP's native exception propagates unchanged — the loader does not wrap it.
 *
 * FQCN derivation: the file path relative to `$directory` is mapped to a
 * class name by replacing directory separators with `\\` and stripping the
 * `.php` extension, then prepending `$namespacePrefix`. Example:
 * `src/Agents/HopliteAgent.php` with prefix `App\\Agents` becomes
 * `App\\Agents\\HopliteAgent`. The prefix must NOT end with `\\`.
 *
 * File discovery uses SPL iterators (`RecursiveDirectoryIterator` +
 * `FilesystemIterator::SKIP_DOTS`) — never `glob()` or `scandir()`.
 *
 * Final — sealed discovery contract.
 */
final class Attribute implements Loader
{
    public function __construct(
        private(set) string $directory,
        private(set) string $namespacePrefix,
    ) {
    }

    public function load(): Registry
    {
        $registry = Registry::empty();

        foreach ($this->discover() as $class) {
            $registry = $registry->with($class);
        }

        return $registry;
    }

    /**
     * Walk $directory, derive FQCNs, and yield each valid discovered Agent.
     *
     * @return iterable<Agent>
     */
    private function discover(): iterable
    {
        if (!is_dir($this->directory)) {
            return;
        }

        // Resolve once — deriveClass() needs the real path on every iteration.
        $realDir = realpath($this->directory);

        if ($realDir === false) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->directory,
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fqcn = $this->deriveClass($file->getPathname(), $realDir);

            if ($fqcn === null || !class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);

            if ($reflection->getAttributes(Discovered::class) === []) {
                continue;
            }

            // Has #[Discovered] — validate the contract.
            if (!$reflection->implementsInterface(Agent::class)) {
                throw LoaderError::notAnAgent($fqcn);
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw LoaderError::nonTrivialConstructor($fqcn);
            }

            if (!$reflection->isInstantiable()) {
                throw LoaderError::notInstantiable($fqcn);
            }

            /** @var Agent $instance */
            $instance = $reflection->newInstance();
            yield $instance;
        }
    }

    /**
     * Derive a fully-qualified class name from an absolute file path.
     * `$realDir` must be the pre-resolved real path of `$this->directory`.
     * Returns null when the path is not within the directory.
     */
    private function deriveClass(string $absolutePath, string $realDir): ?string
    {
        $realPath = realpath($absolutePath);

        if ($realPath === false) {
            return null;
        }

        $relative = substr($realPath, strlen($realDir) + 1);

        if ($relative === '') {
            return null;
        }

        // Strip .php, replace directory separator with namespace separator.
        $className = str_replace(
            ['/', '\\', '.php'],
            ['\\', '\\', ''],
            $relative,
        );

        // Strip trailing .php if not already stripped (Windows paths).
        if (str_ends_with($className, '.php')) {
            $className = substr($className, 0, -4);
        }

        $prefix = rtrim($this->namespacePrefix, '\\');

        return $prefix !== '' ? $prefix . '\\' . $className : $className;
    }
}

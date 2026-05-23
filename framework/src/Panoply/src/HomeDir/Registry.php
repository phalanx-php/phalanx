<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

use Phalanx\Panoply\HomeDir as HomeDirInterface;
use Phalanx\Panoply\HomeDir\AdapterFactory;

/**
 * Immutable registry of {@see HomeDirInterface} adapters available in this
 * environment. Each {@see self::with()} call returns a new instance with the
 * added adapter keyed by its id. Bulk discovery lives in
 * {@see self::autoDetect()}.
 *
 * Final — extension would change immutability semantics.
 */
final class Registry
{
    /**
     * @param array<string, HomeDirInterface> $homeDirs keyed by HomeDir id
     */
    public function __construct(
        private(set) array $homeDirs = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Probe the filesystem for installed AI CLI tools and build a registry
     * of those that are present. The probe algorithm:
     *
     * 1. Enumerate all bundled HomeDir YAML files (`src/HomeDir/*\/*.panoply.yaml`).
     * 2. Load each via {@see Loader}.
     * 3. Resolve `roots` paths against `$home` (substitute `~` → `$home`).
     * 4. Check whether at least one root exists on the filesystem.
     * 5. If yes, call `$config->adapter::fromConfig($config, $home)` to
     *    instantiate the adapter and register it.
     * 6. Missing roots are silently skipped. Malformed YAMLs or instantiation
     *    errors propagate to the caller.
     *
     * @param string $home the user's home directory path
     */
    public static function autoDetect(string $home): self
    {
        $registry = self::empty();

        $bundledDir = dirname(__DIR__, 2) . '/src/HomeDir';

        $iter = new \GlobIterator($bundledDir . '/*/*.panoply.yaml');

        foreach ($iter as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $config = Loader::fromFile($fileInfo->getPathname());

            if (!self::anyRootExists($config->roots, $home)) {
                continue;
            }

            $adapterClass = $config->adapter;

            if (!class_exists($adapterClass)) {
                throw new \LogicException(sprintf(
                    'HomeDir adapter class "%s" referenced in %s does not exist',
                    $adapterClass,
                    $fileInfo->getPathname(),
                ));
            }

            if (!is_a($adapterClass, AdapterFactory::class, allow_string: true)) {
                throw new \LogicException(
                    "HomeDir adapter '{$adapterClass}' must implement " . AdapterFactory::class,
                );
            }

            /** @var class-string<AdapterFactory> $adapterClass */
            $adapter = $adapterClass::fromConfig($config, $home);
            $registry = $registry->with($config->id, $adapter);
        }

        return $registry;
    }

    /**
     * Return a new registry with `$homeDir` added (or replaced) under `$id`.
     */
    public function with(string $id, HomeDirInterface $homeDir): self
    {
        $homeDirs = $this->homeDirs;
        $homeDirs[$id] = $homeDir;

        return new self($homeDirs);
    }

    public function get(string $id): ?HomeDirInterface
    {
        return $this->homeDirs[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->homeDirs[$id]);
    }

    /**
     * @return array<string, HomeDirInterface>
     */
    public function all(): array
    {
        return $this->homeDirs;
    }

    /**
     * @param list<string> $roots
     */
    private static function anyRootExists(array $roots, string $home): bool
    {
        foreach ($roots as $root) {
            $resolved = Config::resolvePath($root, $home);

            if (is_dir($resolved) || is_file($resolved)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Static factory contract for HomeDir adapters. Every bundled adapter
 * implements this interface so that {@see Registry::autoDetect()} can
 * instantiate them without a runtime `method_exists` guard.
 *
 * The `fromConfig` static method receives the loaded YAML config and the
 * resolved home directory, and returns a concrete {@see \Phalanx\Panoply\HomeDir}
 * adapter.
 */
interface AdapterFactory
{
    public static function fromConfig(Config $config, string $home): \Phalanx\Panoply\HomeDir;
}

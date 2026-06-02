<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent;

/**
 * Source-agnostic contract for building an {@see Registry} from a specific
 * origin: a fixed list, a directory scan, a YAML manifest, a pre-built
 * cache file, or a composite of other loaders.
 *
 * Each implementation owns one source of truth and returns a
 * fully-populated Registry. Loaders are stateless after construction —
 * `load()` is idempotent for the same source content.
 */
interface Loader
{
    public function load(): Registry;
}

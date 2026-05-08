<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen;

use Phalanx\Testing\TestLens;
use Phalanx\Testing\TestLensFactory;

/**
 * Resolved metadata for a single registered lens. Produced by LensDiscovery,
 * consumed by AccessorTraitWriter.
 */
final readonly class LensMetadata
{
    /**
     * @param string                          $accessor      Property name on TestApp.
     * @param class-string<TestLens>          $lensClass     Lens class returned by the accessor.
     * @param class-string<TestLensFactory>   $factoryClass  Factory used to construct the lens.
     */
    public function __construct(
        public string $accessor,
        public string $lensClass,
        public string $factoryClass,
    ) {
    }
}

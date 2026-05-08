<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen;

use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;

/**
 * Resolved metadata for a single registered lens. Produced by LensDiscovery,
 * consumed by AccessorTraitWriter.
 */
final readonly class LensMetadata
{
    /**
     * @param string                          $accessor      Property name on TestApp.
     * @param class-string<Lens>          $lensClass     Lens class returned by the accessor.
     * @param class-string<LensFactory>   $factoryClass  Factory used to construct the lens.
     */
    public function __construct(
        public string $accessor,
        public string $lensClass,
        public string $factoryClass,
    ) {
    }
}

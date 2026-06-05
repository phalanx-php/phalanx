<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Artifact;

use Phalanx\AiProviders\Series;

/**
 * Typed series over Artifacts. Consumers can use every inherited
 * combinator immediately; domain-specific filters live alongside the
 * Artifact value object and Store interface.
 *
 * @extends Series<\Phalanx\AiProviders\Artifact>
 */
final class Collection extends Series
{
}

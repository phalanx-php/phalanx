<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Fixtures\Agent\BadDiscovered;

use Phalanx\Panoply\Agent\Discovered;

/**
 * Fixture for Attribute loader error-path tests. This class carries
 * #[Discovered] but does NOT implement Agent. The loader must throw
 * LoaderError::notAnAgent() when it encounters this class.
 */
#[Discovered]
final class BadDiscoveredClass
{
    public string $description { get => 'I claim to be discovered but do not implement Agent.'; }
}

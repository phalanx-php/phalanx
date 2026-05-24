<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Fixtures\Agent\Discovered;

/**
 * Fixture for Attribute loader negative tests. This class carries NO
 * #[Discovered] attribute and does NOT implement Agent. The loader must
 * skip it silently.
 */
final class NonAgentClass
{
    public string $description { get => 'I am not an agent.'; }
}

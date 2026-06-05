<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Fixtures\Testing;

/**
 * Target service for RecordingLens. Stands in for the kind of service a
 * real lens would resolve from TestApp (e.g., HttpApplication, ProviderConfig).
 */
final class RecordingLensTarget
{
    public function __construct(public readonly string $tag = 'real')
    {
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Phalanx\AiProviders\Provider;

/**
 * Resolved provider choice from {@see DemoProvider::ollamaOrFake()}.
 * Carries the provider instance plus routing metadata so demos can
 * surface which execution path is active.
 *
 * Final — sealed value object; the three fields are the complete contract.
 */
final class ProviderChoice
{
    public function __construct(
        private(set) Provider $provider,
        private(set) bool $usingLiveProvider,
        private(set) string $description,
    ) {
    }
}

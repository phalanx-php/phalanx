<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final readonly class HydratedConfig
{
    /** @param list<Issue> $issues */
    public function __construct(
        public ?Config $config,
        public array $issues,
    ) {
    }
}

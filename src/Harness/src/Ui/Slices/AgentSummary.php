<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

final class AgentSummary
{
    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        private(set) string $id,
        private(set) string $name,
        private(set) array $capabilities,
    ) {
    }
}

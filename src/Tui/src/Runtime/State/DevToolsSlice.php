<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

final class DevToolsSlice
{
    /** @var list<string> */
    private(set) array $filters;

    /**
     * @param list<string> $filters
     */
    public function __construct(
        private(set) string $activeTab = 'events',
        private(set) ?string $selectedEventId = null,
        array $filters = [],
    ) {
        if (trim($this->activeTab) === '') {
            throw new \InvalidArgumentException('Dev tools active tab cannot be empty.');
        }

        $this->filters = array_values($filters);
    }
}

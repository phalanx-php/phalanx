<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

final class InputComposerSlice
{
    /** @var list<string> */
    private(set) array $queuedDrafts;

    /**
     * @param list<string> $queuedDrafts
     */
    public function __construct(
        private(set) string $draft = '',
        array $queuedDrafts = [],
    ) {
        $this->queuedDrafts = array_values($queuedDrafts);
    }
}

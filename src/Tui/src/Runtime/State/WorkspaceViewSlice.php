<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

final class WorkspaceViewSlice
{
    public function __construct(
        private(set) string $activePane = 'chat',
        private(set) bool $followCurrentWork = true,
        private(set) ?string $pinnedDetailId = null,
    ) {
        if (trim($this->activePane) === '') {
            throw new \InvalidArgumentException('Workspace active pane cannot be empty.');
        }
    }
}

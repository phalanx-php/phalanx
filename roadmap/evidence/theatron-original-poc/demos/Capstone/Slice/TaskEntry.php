<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

final class TaskEntry
{
    public function __construct(
        private(set) string $id,
        private(set) string $title,
        private(set) string $assignedTo,
        private(set) string $status = 'pending',
    ) {
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->title, $this->assignedTo, $status);
    }
}

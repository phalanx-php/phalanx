<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

final readonly class TaskResource
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public TaskPriority $priority,
        public TaskStatus $status,
    ) {}
}

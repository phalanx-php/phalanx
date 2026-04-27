<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

final readonly class ListTasksQuery
{
    public function __construct(
        public int $page = 1,
        public int $limit = 25,
        public ?TaskStatus $status = null,
        public ?string $search = null,
    ) {}
}

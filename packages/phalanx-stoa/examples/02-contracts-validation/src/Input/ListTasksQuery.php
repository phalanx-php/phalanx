<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Contracts\Input;

final readonly class ListTasksQuery
{
    public function __construct(
        public string $owner = 'all',
        public int $limit = 10,
    ) {
    }
}

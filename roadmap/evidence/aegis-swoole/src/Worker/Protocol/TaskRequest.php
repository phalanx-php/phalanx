<?php

declare(strict_types=1);

namespace AegisSwoole\Worker\Protocol;

final readonly class TaskRequest
{
    public function __construct(
        public string $id,
        public string $serializedTask,
    ) {
    }
}

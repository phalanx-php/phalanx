<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

final class ManagedRunnerEvents
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $event): void
    {
        $this->entries[] = $event;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Harness\Ui\Slices\PendingEffect;
use Phalanx\Panoply\Cue;

interface AgentExecutorContract
{
    /** @return iterable<Cue> */
    public function send(string $message): iterable;

    /** @return iterable<Cue> */
    public function approve(PendingEffect $effect): iterable;

    /** @return iterable<Cue> */
    public function deny(PendingEffect $effect): iterable;

    public function cancel(): void;
}

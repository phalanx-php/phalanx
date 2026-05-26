<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Harness\Ui\Slices\PendingEffect;
use Phalanx\Panoply\Cue;

interface AgentExecutorContract
{
    /**
     * Send a user message and get back a cue stream.
     *
     * @return iterable<Cue>
     */
    public function send(string $message): iterable;

    /**
     * Approve the currently pending effect. Athena's suspended activity flow owns resumption.
     *
     * @return iterable<Cue>
     */
    public function approve(PendingEffect $effect): iterable;

    /**
     * Deny the currently pending effect.
     *
     * @return iterable<Cue>
     */
    public function deny(PendingEffect $effect): iterable;

    public function cancel(): void;
}

<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Panoply\Cue;
use Phalanx\Theatron\Template\Slice\PendingEffect;

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

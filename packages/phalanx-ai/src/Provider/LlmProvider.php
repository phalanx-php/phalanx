<?php

declare(strict_types=1);

namespace Phalanx\Ai\Provider;

use Phalanx\Stream\Emitter;

interface LlmProvider
{
    /** @return Emitter Returns an Emitter of AgentEvent */
    public function generate(GenerateRequest $request): Emitter;
}

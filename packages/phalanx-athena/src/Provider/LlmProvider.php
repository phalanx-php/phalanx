<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Styx\Emitter;

interface LlmProvider
{
    /** @return Emitter Returns an Emitter of AgentEvent */
    public function generate(GenerateRequest $request): Emitter;
}

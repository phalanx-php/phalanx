<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Conversation;

/**
 * Contract for objects that turn a tool-specific conversation source into a
 * normalized {@see Log}. Implementations are stateless across calls —
 * all per-call configuration flows through the optional {@see Options}.
 */
interface Parser
{
    public function parse(Source $source, ?Options $options = null): Log;
}

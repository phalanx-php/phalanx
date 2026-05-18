<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Closed enumeration of common provider/model capabilities. Novel
 * vendor-specific capabilities use {@see Capabilities::withCustom()} to
 * carry an opaque string surface alongside the closed set.
 */
enum Capability: string
{
    case Reasoning        = 'reasoning';
    case ToolUse          = 'tool-use';
    case StructuredOutput = 'structured-output';
    case Vision           = 'vision';
    case PromptCaching    = 'prompt-caching';
    case ExtendedThinking = 'extended-thinking';
    case ParallelTools    = 'parallel-tools';
    case JsonMode         = 'json-mode';
    case AudioInput       = 'audio-input';
    case AudioOutput      = 'audio-output';
    case VideoInput       = 'video-input';
    case FunctionCalling  = 'function-calling';
    case Streaming        = 'streaming';
    case Batch            = 'batch';
}

<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Output;

/**
 * Discriminator for which output channel a {@see TokenDelta} belongs to.
 * Different providers expose different channels under different names —
 * Anthropic's extended thinking, OpenAI's reasoning tokens, Gemini's
 * thoughts — but the panoply taxonomy normalizes them onto this enum so
 * consumers can render or filter uniformly.
 */
enum Channel: string
{
    case Message = 'message';
    case Thinking = 'thinking';
    case Reasoning = 'reasoning';
}

<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

/**
 * Provider-instance configuration for the Anthropic Messages API. Carries
 * settings that apply to every invocation handled by a given provider
 * instance, as opposed to per-request dynamic data.
 *
 * Optional fields (`temperature`, `topP`, `stopSequences`) are omitted from
 * the wire request when they hold their default (null / empty), preserving
 * Anthropic's server-side defaults for those parameters.
 *
 * Final — sealed value object; extension is neither needed nor safe.
 */
final class MessagesOptions
{
    /**
     * @param int          $maxTokens      Maximum number of tokens to generate.
     *                                     Defaults to 4096; increase for models
     *                                     with larger output windows.
     * @param float|null   $temperature    Sampling temperature. Null defers to
     *                                     Anthropic's server-side default.
     * @param float|null   $topP           Nucleus sampling probability. Null
     *                                     defers to Anthropic's server-side
     *                                     default.
     * @param list<string> $stopSequences  Sequences that halt generation.
     *                                     Empty list omits the field entirely.
     */
    public function __construct(
        private(set) int $maxTokens = 4096,
        private(set) ?float $temperature = null,
        private(set) ?float $topP = null,
        private(set) array $stopSequences = [],
    ) {
    }
}

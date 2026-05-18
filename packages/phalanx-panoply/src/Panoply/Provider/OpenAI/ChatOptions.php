<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\OpenAI;

/**
 * Provider-instance configuration for the OpenAI Chat Completions API.
 * Carries settings that apply to every invocation handled by a given
 * provider instance, as opposed to per-request dynamic data.
 *
 * All fields are optional (null / empty defaults). When a field is null
 * it is omitted from the wire request, preserving OpenAI's server-side
 * defaults for that parameter.
 *
 * Final — sealed value object; extension is neither needed nor safe.
 */
final class ChatOptions
{
    /**
     * @param int|null          $maxTokens   Maximum tokens to generate. Null defers to OpenAI's
     *                                       model-aware default.
     * @param float|null        $temperature Sampling temperature. Null defers to OpenAI's default.
     * @param float|null        $topP        Nucleus sampling probability. Null defers to OpenAI's default.
     * @param list<string>      $stop        Stop sequences. Empty list omits the field.
     * @param int|null          $seed        Integer seed for deterministic sampling. Null omits the field.
     */
    public function __construct(
        private(set) ?int $maxTokens = null,
        private(set) ?float $temperature = null,
        private(set) ?float $topP = null,
        private(set) array $stop = [],
        private(set) ?int $seed = null,
    ) {
    }
}

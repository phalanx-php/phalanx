<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\OpenAI;

/**
 * Provider-instance configuration for the OpenAI Responses API.
 * Carries settings that apply to every invocation handled by a given
 * provider instance.
 *
 * All fields are optional; null values are omitted from the wire request,
 * deferring to OpenAI's server-side defaults. `reasoningEffort` accepts
 * the string literals "low", "medium", or "high" and maps to the
 * `reasoning.effort` body field.
 *
 * Final — sealed value object; extension is neither needed nor safe.
 */
final class ResponsesOptions
{
    /**
     * @param int|null    $maxOutputTokens Maximum tokens in the response. Null defers to OpenAI's default.
     * @param float|null  $temperature     Sampling temperature. Null defers to OpenAI's default.
     * @param float|null  $topP            Nucleus sampling probability. Null defers to OpenAI's default.
     * @param string|null $reasoningEffort "low", "medium", or "high". Null omits the reasoning field.
     */
    public function __construct(
        private(set) ?int $maxOutputTokens = null,
        private(set) ?float $temperature = null,
        private(set) ?float $topP = null,
        private(set) ?string $reasoningEffort = null,
    ) {
    }
}

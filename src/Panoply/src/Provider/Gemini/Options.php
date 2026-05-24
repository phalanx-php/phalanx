<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Gemini;

/**
 * Optional generation parameters for the Gemini Generative Language API.
 * Every field is nullable; unset fields are omitted from the request body
 * so Gemini's server defaults take effect.
 *
 * The {@see $thinkingBudget} field applies to the Gemini 2.5 series:
 * "low" | "medium" | "high". When null, no thinking configuration is sent.
 *
 * Final — sealed value object; the field set is a closed contract.
 */
final class Options
{
    /**
     * @param list<string> $stopSequences
     */
    public function __construct(
        private(set) ?int $maxOutputTokens = null,
        private(set) ?float $temperature = null,
        private(set) ?float $topP = null,
        private(set) ?int $topK = null,
        private(set) array $stopSequences = [],
        /** "low" | "medium" | "high" — controls Gemini 2.5 thinking budget. */
        private(set) ?string $thinkingBudget = null,
    ) {
    }
}

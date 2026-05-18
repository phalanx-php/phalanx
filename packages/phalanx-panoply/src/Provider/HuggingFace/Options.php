<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\HuggingFace;

/**
 * Optional generation parameters for the Hugging Face Inference API.
 * Every field is nullable; unset fields are omitted from the request body
 * so the model's server defaults take effect.
 *
 * The {@see $topK} and {@see $doSample} fields are HuggingFace-specific
 * extensions to the OpenAI-compatible chat-completions body. Standard
 * OpenAI clients ignore them; HuggingFace-hosted models respect them.
 *
 * Final — sealed value object; the field set is a closed contract.
 */
final class Options
{
    public function __construct(
        private(set) ?float $temperature = null,
        private(set) ?float $topP = null,
        private(set) ?int $topK = null,
        private(set) ?int $maxNewTokens = null,
        private(set) ?bool $doSample = null,
    ) {
    }
}

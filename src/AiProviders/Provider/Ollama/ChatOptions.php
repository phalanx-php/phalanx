<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Ollama;

/**
 * Provider-instance configuration for the Ollama Chat API. Maps onto
 * Ollama's `options` body field. Null values are omitted from the wire
 * request, deferring to Ollama's model-level defaults.
 *
 * Final — sealed value object; extension is neither needed nor safe.
 */
final class ChatOptions
{
    /**
     * @param float|null   $temperature Sampling temperature. Null defers to Ollama's default.
     * @param int|null     $numPredict  Maximum tokens to predict. Null defers to Ollama's default.
     * @param float|null   $topP        Nucleus sampling probability. Null defers to Ollama's default.
     * @param list<string> $stop        Stop sequences. Empty list omits the field.
     */
    public function __construct(
        private(set) ?float $temperature = null,
        private(set) ?int $numPredict = null,
        private(set) ?float $topP = null,
        private(set) array $stop = [],
    ) {
    }
}

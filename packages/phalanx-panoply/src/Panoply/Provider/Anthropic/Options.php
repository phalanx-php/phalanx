<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

/**
 * Provider-instance configuration for the Anthropic Messages API. Carries
 * settings that apply to every invocation handled by a given provider
 * instance, as opposed to per-request dynamic data.
 *
 * Final — sealed value object; extension is neither needed nor safe.
 */
final class Options
{
    /**
     * @param int $maxTokens Maximum number of tokens to generate. Defaults to
     *                       4096; increase for models with larger output windows.
     */
    public function __construct(
        private(set) int $maxTokens = 4096,
    ) {
    }
}

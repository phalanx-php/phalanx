<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Config;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * Immutable value object for one model entry inside a provider config.
 * Carries the model's display name, vendor model identifier, aliases,
 * capability advertisement, and optional pricing per-token (in USD).
 *
 * Aliases are sorted in {@see self::toCanonical()} to guarantee hash
 * determinism regardless of declaration order in the YAML source.
 *
 * Final — subclassing would alter {@see self::toCanonical()} and break
 * Canonical hash stability.
 */
final class Model implements Canonicalizable
{
    /**
     * @param list<string> $aliases
     */
    public function __construct(
        private(set) string $name,
        private(set) string $modelId,
        private(set) array $aliases,
        private(set) Capabilities $capabilities,
        private(set) ?float $inputPricing = null,
        private(set) ?float $outputPricing = null,
    ) {
    }

    /**
     * @param list<string> $aliases
     */
    public static function of(
        string $name,
        string $modelId,
        array $aliases,
        Capabilities $capabilities,
        ?float $inputPricing = null,
        ?float $outputPricing = null,
    ): self {
        return new self(
            name: $name,
            modelId: $modelId,
            aliases: $aliases,
            capabilities: $capabilities,
            inputPricing: $inputPricing,
            outputPricing: $outputPricing,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        $aliases = $this->aliases;
        sort($aliases);

        return [
            'name' => $this->name,
            'model_id' => $this->modelId,
            'aliases' => $aliases,
            'capabilities' => $this->capabilities->toCanonical(),
            'input_pricing' => $this->inputPricing,
            'output_pricing' => $this->outputPricing,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Hash\Canonicalizable;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;

/**
 * Immutable value object for a loaded provider configuration. Models the
 * provider YAML structure: id, display name, model list, capability
 * advertisement, transport requirements, and an optional wire-translator
 * class-string.
 *
 * `wireTranslator` carries the class-string of the concrete {@see \Phalanx\Panoply\Provider}
 * implementation for this config. When the adapter class is not installed
 * in the current environment, {@see Loader} sets this field to null — the
 * config loads cleanly but cannot produce a live Provider instance without
 * the adapter package.
 *
 * Final — subclassing would alter {@see self::toCanonical()} and break
 * Canonical hash stability.
 */
final class Config implements Canonicalizable
{
    /**
     * @param list<Config\Model> $models
     * @param class-string<\Phalanx\Panoply\Provider>|null $wireTranslator
     */
    public function __construct(
        private(set) string $id,
        private(set) string $displayName,
        private(set) array $models,
        private(set) Capabilities $capabilities,
        private(set) TransportNeeds $transport,
        private(set) ?string $wireTranslator,
    ) {
    }

    /**
     * @param list<Config\Model> $models
     * @param class-string<\Phalanx\Panoply\Provider>|null $wireTranslator
     */
    public static function of(
        string $id,
        string $displayName,
        array $models,
        Capabilities $capabilities,
        TransportNeeds $transport,
        ?string $wireTranslator = null,
    ): self {
        return new self(
            id: $id,
            displayName: $displayName,
            models: $models,
            capabilities: $capabilities,
            transport: $transport,
            wireTranslator: $wireTranslator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id'              => $this->id,
            'display_name'    => $this->displayName,
            'models'          => array_map(
                static fn (Config\Model $m): array => $m->toCanonical(),
                $this->models,
            ),
            'capabilities'    => $this->capabilities->toCanonical(),
            'transport'       => $this->transport->toCanonical(),
            'wire_translator' => $this->wireTranslator,
        ];
    }
}

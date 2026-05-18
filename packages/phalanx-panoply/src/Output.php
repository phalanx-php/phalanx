<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * The shape of work an agent commits to producing. One of three modes:
 *
 * - {@see Mode::Text}: free-form prose response only.
 * - {@see Mode::Artifact}: a typed durable artifact of a declared kind.
 * - {@see Mode::Structured}: a structured payload validated against a
 *   schema (carried as a class-string reference; resolution happens in
 *   the agent runtime).
 *
 * The agent contract surfaces only the declaration; assembly and
 * validation live downstream.
 *
 * Final because the canonical hash is load-bearing: subclassing would
 * alter toCanonical() and break hash stability across consumers.
 */
final class Output implements Canonicalizable
{
    /**
     * @param class-string|null $schema
     */
    private function __construct(
        private(set) Output\Mode $mode,
        private(set) ?ArtifactKind $artifactKind = null,
        private(set) ?string $schema = null,
    ) {
    }

    public static function text(): self
    {
        return new self(Output\Mode::Text);
    }

    public static function artifact(ArtifactKind $kind): self
    {
        return new self(Output\Mode::Artifact, artifactKind: $kind);
    }

    /**
     * @param class-string $schema
     */
    public static function structured(string $schema): self
    {
        return new self(Output\Mode::Structured, schema: $schema);
    }

    /**
     * @return array{mode: string, artifact_kind: string|null, schema: string|null}
     */
    public function toCanonical(): array
    {
        return [
            'mode'          => $this->mode->value,
            'artifact_kind' => $this->artifactKind?->value,
            'schema'        => $this->schema,
        ];
    }
}

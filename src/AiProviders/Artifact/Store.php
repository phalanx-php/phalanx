<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Artifact;

use Phalanx\AiProviders\Artifact;

/**
 * Contract for objects that persist and retrieve {@see Artifact} instances.
 * Implementations decide storage strategy (in-memory, database, filesystem).
 * All retrieval methods return typed results: `byId` returns null on miss
 * while `byActivity` and `all` return empty {@see Collection} instances.
 */
interface Store
{
    /**
     * Persist the artifact. Implementations MUST overwrite an existing
     * record with the same id (upsert semantics) — Artifacts are
     * immutable from the host's perspective, so re-saving a finalized
     * artifact with the same id is idempotent.
     */
    public function save(Artifact $artifact): void;

    public function byId(string $id): ?Artifact;

    public function byActivity(string $activityId): Collection;

    public function all(): Collection;
}

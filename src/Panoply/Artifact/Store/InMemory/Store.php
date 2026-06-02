<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Artifact\Store\InMemory;

use Phalanx\Panoply\Artifact;
use Phalanx\Panoply\Artifact\Collection;
use Phalanx\Panoply\Artifact\Store as StoreContract;

/**
 * Array-backed {@see StoreContract}. {@see self::save()} is upsert per
 * the Store contract — re-saving an artifact with the same id overwrites
 * the previous record. {@see self::byActivity()} filters in-memory and
 * returns a typed {@see Collection}.
 *
 * Final — extension would alter upsert semantics that consumers depend on.
 */
final class Store implements StoreContract
{
    /** @var array<string, Artifact> */
    private array $artifacts = [];

    public function save(Artifact $artifact): void
    {
        $this->artifacts[$artifact->id] = $artifact;
    }

    public function byId(string $id): ?Artifact
    {
        return $this->artifacts[$id] ?? null;
    }

    public function byActivity(string $activityId): Collection
    {
        $artifacts = $this->artifacts;

        return new Collection(static function () use ($artifacts, $activityId): \Generator {
            foreach ($artifacts as $artifact) {
                if ($artifact->activityId === $activityId) {
                    yield $artifact;
                }
            }
        });
    }

    public function all(): Collection
    {
        $artifacts = $this->artifacts;

        return new Collection(static function () use ($artifacts): \Generator {
            yield from $artifacts;
        });
    }
}

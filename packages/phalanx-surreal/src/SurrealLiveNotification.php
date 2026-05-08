<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

final class SurrealLiveNotification
{
    public function __construct(
        public private(set) SurrealLiveAction $action,
        public private(set) string $queryId,
        public private(set) mixed $result,
    ) {
    }

    /** @param array<array-key, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;
        if (!is_string($id)) {
            throw new SurrealException('Surreal live notification query id was missing.');
        }

        return new self(
            action: SurrealLiveAction::fromPayload($payload['action'] ?? null),
            queryId: $id,
            result: $payload['result'] ?? null,
        );
    }
}

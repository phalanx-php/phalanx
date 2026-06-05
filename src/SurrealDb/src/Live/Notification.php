<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

final class Notification
{
    public function __construct(
        private(set) \Phalanx\SurrealDb\Live\Action $action,
        private(set) string $queryId,
        private(set) mixed $result,
    ) {
    }

    /** @param array<array-key, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;
        if (!is_string($id)) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb live notification query id was missing.');
        }

        return new self(
            action: \Phalanx\SurrealDb\Live\Action::fromPayload($payload['action'] ?? null),
            queryId: $id,
            result: $payload['result'] ?? null,
        );
    }
}

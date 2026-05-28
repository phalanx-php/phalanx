<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream;

interface SerializableStreamEvent extends StreamEvent
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static;

    /** @return array<string, mixed> */
    public function toPayload(): array;
}

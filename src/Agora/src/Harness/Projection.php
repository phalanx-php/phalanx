<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

interface Projection
{
    public function apply(
        HarnessEvent $event,
    ): self;

    public function checkpoint(
        ?\DateTimeImmutable $createdAt = null,
    ): ProjectionCheckpoint;

    public function eventSequence(): int;

    public function kind(): ProjectionKind;

    /** @return array<string, mixed> */
    public function state(): array;
}

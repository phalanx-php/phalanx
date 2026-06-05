<?php

declare(strict_types=1);

namespace Phalanx\Agents\Persistence;

use Phalanx\Agents\Effect\Resolution;

final class EffectLogRecord
{
    public function __construct(
        private(set) string $id,
        private(set) string $invocationId,
        private(set) string $kind,
        private(set) string $toolName,
        private(set) string $argsHash,
        private(set) Resolution $resolution,
        private(set) string $outcome,
        private(set) \DateTimeImmutable $at,
    ) {
    }
}

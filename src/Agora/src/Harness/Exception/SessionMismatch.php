<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Exception;

final class SessionMismatch extends \LogicException
{
    public static function expected(
        string $expected,
        string $actual,
    ): self {
        return new self(sprintf(
            'Harness event for session %s cannot be applied to projection for session %s.',
            $actual,
            $expected,
        ));
    }
}

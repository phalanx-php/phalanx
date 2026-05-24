<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Exception;

final class OutOfOrderEventSequence extends \LogicException
{
    public static function expected(
        int $expected,
        int $actual,
    ): self {
        return new self(sprintf(
            'Harness event sequence %d cannot be applied; expected %d.',
            $actual,
            $expected,
        ));
    }
}

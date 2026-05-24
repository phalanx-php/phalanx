<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Exception;

final class DuplicateEventSequence extends \LogicException
{
    public static function forSequence(
        int $sequence,
    ): self {
        return new self(sprintf(
            'Harness event sequence %d has already been applied.',
            $sequence,
        ));
    }
}

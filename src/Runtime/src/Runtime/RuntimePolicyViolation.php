<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use RuntimeException;
use Throwable;

final class RuntimePolicyViolation extends RuntimeException
{
    public static function missingRequiredFlags(RuntimePolicy $policy, int $missingFlags): self
    {
        return new self(sprintf(
            'Swoole runtime policy "%s" is missing required hook flags: %s.',
            $policy->name,
            implode(', ', SwooleHook::namesForMask($missingFlags)),
        ));
    }

    public static function unavailableRequiredFlags(RuntimePolicy $policy, int $unavailableFlags): self
    {
        return new self(sprintf(
            'Swoole runtime policy "%s" requires hook flags that are unavailable in this Swoole build: %s.',
            $policy->name,
            implode(', ', SwooleHook::namesForMask($unavailableFlags)),
        ));
    }

    public static function enableFailed(RuntimePolicy $policy, int $missingFlags, Throwable $previous): self
    {
        return new self(sprintf(
            'Swoole runtime policy "%s" could not enable required hook flags: %s.',
            $policy->name,
            implode(', ', SwooleHook::namesForMask($missingFlags)),
        ), previous: $previous);
    }
}

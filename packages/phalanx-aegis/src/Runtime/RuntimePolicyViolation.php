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
            'OpenSwoole runtime policy "%s" is missing required hook flags: %s.',
            $policy->name,
            implode(', ', RuntimeHookNames::forMask($missingFlags)),
        ));
    }

    public static function enableFailed(RuntimePolicy $policy, int $missingFlags, Throwable $previous): self
    {
        return new self(sprintf(
            'OpenSwoole runtime policy "%s" could not enable required hook flags: %s.',
            $policy->name,
            implode(', ', RuntimeHookNames::forMask($missingFlags)),
        ), previous: $previous);
    }
}

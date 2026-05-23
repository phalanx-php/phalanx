<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use RuntimeException;

final class MissingRequestCtxValue extends RuntimeException
{
    /** @param RequestCtxKey<mixed> $key */
    public static function forKey(RequestCtxKey $key): self
    {
        return new self("Request context value is not set: {$key->key()}");
    }
}

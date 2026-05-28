<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class StoreNotStarted extends StoreException
{
    public static function writer(): self
    {
        return new self('Store writer is not running. Start StoreRegistry inside an ExecutionScope before writing.');
    }
}

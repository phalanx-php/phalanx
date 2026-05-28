<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

final class SpecLoadException extends \RuntimeException
{
    public static function for(string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self("failed to load specialist spec {$path}: {$reason}", 0, $previous);
    }
}

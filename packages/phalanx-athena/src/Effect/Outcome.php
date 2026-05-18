<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;

final class Outcome
{
    public function __construct(
        private(set) Resolution $resolution,
        private(set) ?PanoplyOutcome $effect = null,
        private(set) mixed $data = null,
        private(set) ?\Throwable $error = null,
    ) {
    }

    public static function routed(Resolution $resolution, ?PanoplyOutcome $effect = null, mixed $data = null): self
    {
        return new self($resolution, $effect, $data);
    }

    public static function failed(Resolution $resolution, \Throwable $error, ?PanoplyOutcome $effect = null): self
    {
        return new self($resolution, $effect, error: $error);
    }
}

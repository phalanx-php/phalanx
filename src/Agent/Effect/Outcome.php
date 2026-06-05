<?php

declare(strict_types=1);

namespace Phalanx\Agent\Effect;

use Phalanx\AiProviders\Effect\Outcome as AiProvidersOutcome;

final class Outcome
{
    private function __construct(
        private(set) Resolution $resolution,
        private(set) ?AiProvidersOutcome $effect = null,
        private(set) mixed $data = null,
        private(set) ?\Throwable $error = null,
        private(set) bool $halt = false,
    ) {
    }

    public static function routed(Resolution $resolution, ?AiProvidersOutcome $effect = null, mixed $data = null): self
    {
        return new self($resolution, $effect, $data);
    }

    public static function halted(Resolution $resolution, ?AiProvidersOutcome $effect = null): self
    {
        return new self($resolution, $effect, halt: true);
    }

    public static function failed(Resolution $resolution, \Throwable $error, ?AiProvidersOutcome $effect = null): self
    {
        return new self($resolution, $effect, error: $error);
    }
}

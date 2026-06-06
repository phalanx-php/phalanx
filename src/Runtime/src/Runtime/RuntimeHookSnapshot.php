<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

final readonly class RuntimeHookSnapshot
{
    private function __construct(
        public string $policyName,
        public int $currentFlags,
        public int $availableFlags,
        public int $requiredFlags,
        public int $missingFlags,
        public int $unavailableRequiredFlags,
        public int $sensitiveEnabledFlags,
    ) {
    }

    public static function capture(RuntimePolicy $policy): self
    {
        return self::fromFlags($policy, RuntimeHooks::currentFlags());
    }

    public static function fromFlags(RuntimePolicy $policy, int $currentFlags, ?int $availableFlags = null): self
    {
        $availableFlags ??= SwooleHook::availableMask();

        return new self(
            policyName: $policy->name,
            currentFlags: $currentFlags,
            availableFlags: $availableFlags,
            requiredFlags: $policy->requiredFlags,
            missingFlags: $policy->missingFlags($currentFlags),
            unavailableRequiredFlags: $policy->unavailableRequiredFlags($availableFlags),
            sensitiveEnabledFlags: $policy->sensitiveEnabledFlags($currentFlags),
        );
    }

    public function isHealthy(): bool
    {
        return $this->missingFlags === 0 && $this->unavailableRequiredFlags === 0;
    }

    /** @return list<string> */
    public function currentFlagNames(): array
    {
        return SwooleHook::namesForMask($this->currentFlags);
    }

    /** @return list<string> */
    public function availableFlagNames(): array
    {
        return SwooleHook::namesForMask($this->availableFlags);
    }

    /** @return list<string> */
    public function requiredFlagNames(): array
    {
        return SwooleHook::namesForMask($this->requiredFlags);
    }

    /** @return list<string> */
    public function missingFlagNames(): array
    {
        return SwooleHook::namesForMask($this->missingFlags);
    }

    /** @return list<string> */
    public function unavailableRequiredFlagNames(): array
    {
        return SwooleHook::namesForMask($this->unavailableRequiredFlags);
    }

    /** @return list<string> */
    public function sensitiveEnabledFlagNames(): array
    {
        return SwooleHook::namesForMask($this->sensitiveEnabledFlags);
    }

    /** @return array{policy: string, current: int, available: int, required: int, missing: int, unavailable_required: int, sensitive_enabled: int, healthy: bool} */
    public function toArray(): array
    {
        return [
            'policy' => $this->policyName,
            'current' => $this->currentFlags,
            'available' => $this->availableFlags,
            'required' => $this->requiredFlags,
            'missing' => $this->missingFlags,
            'unavailable_required' => $this->unavailableRequiredFlags,
            'sensitive_enabled' => $this->sensitiveEnabledFlags,
            'healthy' => $this->isHealthy(),
        ];
    }
}

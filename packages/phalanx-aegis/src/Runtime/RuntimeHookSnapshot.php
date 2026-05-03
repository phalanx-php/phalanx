<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

final readonly class RuntimeHookSnapshot
{
    private function __construct(
        public string $policyName,
        public int $currentFlags,
        public int $requiredFlags,
        public int $missingFlags,
        public int $sensitiveEnabledFlags,
    ) {
    }

    public static function capture(RuntimePolicy $policy): self
    {
        return self::fromFlags($policy, RuntimeHooks::currentFlags());
    }

    public static function fromFlags(RuntimePolicy $policy, int $currentFlags): self
    {
        return new self(
            policyName: $policy->name,
            currentFlags: $currentFlags,
            requiredFlags: $policy->requiredFlags,
            missingFlags: $policy->missingFlags($currentFlags),
            sensitiveEnabledFlags: $policy->sensitiveEnabledFlags($currentFlags),
        );
    }

    public function isHealthy(): bool
    {
        return $this->missingFlags === 0;
    }

    /** @return list<string> */
    public function currentFlagNames(): array
    {
        return RuntimeHookNames::forMask($this->currentFlags);
    }

    /** @return list<string> */
    public function requiredFlagNames(): array
    {
        return RuntimeHookNames::forMask($this->requiredFlags);
    }

    /** @return list<string> */
    public function missingFlagNames(): array
    {
        return RuntimeHookNames::forMask($this->missingFlags);
    }

    /** @return list<string> */
    public function sensitiveEnabledFlagNames(): array
    {
        return RuntimeHookNames::forMask($this->sensitiveEnabledFlags);
    }

    /** @return array{policy: string, current: int, required: int, missing: int, sensitive_enabled: int, healthy: bool} */
    public function toArray(): array
    {
        return [
            'policy' => $this->policyName,
            'current' => $this->currentFlags,
            'required' => $this->requiredFlags,
            'missing' => $this->missingFlags,
            'sensitive_enabled' => $this->sensitiveEnabledFlags,
            'healthy' => $this->isHealthy(),
        ];
    }
}

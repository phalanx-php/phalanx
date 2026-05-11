<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class Check
{
    private function __construct(
        private(set) string $name,
        private(set) CheckStatus $status,
        private(set) string $message,
        private(set) ?string $remediation = null,
    ) {
    }

    public static function pass(string $name, string $message = 'ok'): self
    {
        return new self($name, CheckStatus::Pass, $message);
    }

    public static function warn(string $name, string $message, ?string $remediation = null): self
    {
        return new self($name, CheckStatus::Warn, $message, $remediation);
    }

    public static function fail(string $name, string $message, ?string $remediation = null): self
    {
        return new self($name, CheckStatus::Fail, $message, $remediation);
    }

    public function isPass(): bool
    {
        return $this->status === CheckStatus::Pass;
    }

    public function isFail(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    public function isWarn(): bool
    {
        return $this->status === CheckStatus::Warn;
    }
}

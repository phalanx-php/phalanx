<?php

declare(strict_types=1);

namespace Phalanx\Boot;

final readonly class BootEvaluation
{
    /** @param 'pass'|'warn'|'fail' $status */
    private function __construct(
        public string $status,
        public string $message,
        public ?string $remediation = null,
    ) {
    }

    public static function pass(string $message = 'ok'): self
    {
        return new self('pass', $message);
    }

    public static function warn(string $message, ?string $remediation = null): self
    {
        return new self('warn', $message, $remediation);
    }

    public static function fail(string $message, ?string $remediation = null): self
    {
        return new self('fail', $message, $remediation);
    }

    public function isPass(): bool
    {
        return $this->status === 'pass';
    }

    public function isFail(): bool
    {
        return $this->status === 'fail';
    }

    public function isWarn(): bool
    {
        return $this->status === 'warn';
    }
}

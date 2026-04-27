<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Concurrency\Settlement;
use PHPUnit\Framework\Assert as PHPUnitAssert;

final readonly class SettlementExpectation
{
    private function __construct(
        public bool $expectOk,
        public mixed $expectedValue = null,
        public bool $checkValue = false,
        /** @var class-string<\Throwable>|null */
        public ?string $exceptionClass = null,
        public ?string $message = null,
    ) {}

    public static function ok(mixed $value = null, bool $checkValue = false): self
    {
        return new self(
            expectOk: true,
            expectedValue: $value,
            checkValue: $value !== null || $checkValue,
        );
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    public static function failed(string $exceptionClass, ?string $message = null): self
    {
        return new self(expectOk: false, exceptionClass: $exceptionClass, message: $message);
    }

    public function verify(string|int $key, Settlement $settlement): void
    {
        if ($this->expectOk) {
            PHPUnitAssert::assertTrue($settlement->isOk, "Expected settlement '$key' to succeed, but it failed");

            if ($this->checkValue) {
                PHPUnitAssert::assertSame($this->expectedValue, $settlement->value, "Settlement '$key' value mismatch");
            }

            return;
        }

        PHPUnitAssert::assertFalse($settlement->isOk, "Expected settlement '$key' to fail, but it succeeded");

        if ($this->exceptionClass !== null) {
            PHPUnitAssert::assertInstanceOf(
                $this->exceptionClass,
                $settlement->error,
                "Settlement '$key' exception type mismatch",
            );
        }

        if ($this->message !== null && $settlement->error !== null) {
            PHPUnitAssert::assertSame(
                $this->message,
                $settlement->error->getMessage(),
                "Settlement '$key' error message mismatch",
            );
        }
    }
}

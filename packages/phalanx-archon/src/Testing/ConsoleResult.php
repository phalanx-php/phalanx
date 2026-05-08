<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use PHPUnit\Framework\Assert;

/**
 * Captured outcome of a single ConsoleLens::run() invocation.
 */
final readonly class ConsoleResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function assertSuccessful(): self
    {
        Assert::assertSame(
            0,
            $this->exitCode,
            "Expected exit 0; got {$this->exitCode}.\nStdout:\n{$this->stdout}\nStderr:\n{$this->stderr}",
        );

        return $this;
    }

    public function assertExitCode(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->exitCode,
            "Expected exit {$expected}; got {$this->exitCode}.",
        );

        return $this;
    }

    public function assertOutputContains(string $needle): self
    {
        Assert::assertStringContainsString(
            $needle,
            $this->stdout,
            "Stdout did not contain expected substring.\nGot:\n{$this->stdout}",
        );

        return $this;
    }

    public function assertOutputDoesNotContain(string $needle): self
    {
        Assert::assertStringNotContainsString(
            $needle,
            $this->stdout,
            'Stdout contained an unexpected substring.',
        );

        return $this;
    }

    public function assertOutputMatches(string $pattern): self
    {
        Assert::assertMatchesRegularExpression(
            $pattern,
            $this->stdout,
            'Stdout did not match expected pattern.',
        );

        return $this;
    }
}

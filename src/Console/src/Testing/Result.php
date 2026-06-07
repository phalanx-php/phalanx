<?php

declare(strict_types=1);

namespace Phalanx\Console\Testing;

use Phalanx\Runtime\Memory\ManagedResourceState;
use PHPUnit\Framework\Assert;

/**
 * Captured outcome of a single Lens::run() invocation.
 */
final readonly class Result
{
    /** @param list<ManagedResourceState> $commandResourceStates */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $liveCommandResources = 0,
        public int $liveRuntimeScopes = 0,
        public int $liveTasks = 0,
        public array $commandResourceStates = [],
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

    public function assertNoLiveCommandResources(): self
    {
        Assert::assertSame(
            0,
            $this->liveCommandResources,
            "Expected no live console command resources; {$this->liveCommandResources} still live.",
        );

        return $this;
    }

    public function assertNoLiveRuntimeScopes(): self
    {
        Assert::assertSame(
            0,
            $this->liveRuntimeScopes,
            "Expected no live runtime scopes; {$this->liveRuntimeScopes} still live.",
        );

        return $this;
    }

    public function assertNoLiveTasks(): self
    {
        Assert::assertSame(
            0,
            $this->liveTasks,
            "Expected no live tasks; {$this->liveTasks} still live.",
        );

        return $this;
    }

    public function assertCommandResourcesClosed(int $expected = 1): self
    {
        Assert::assertCount(
            $expected,
            $this->commandResourceStates,
            "Expected {$expected} tracked console command resource(s).",
        );

        foreach ($this->commandResourceStates as $state) {
            Assert::assertSame(
                ManagedResourceState::Closed,
                $state,
                'Expected every tracked console command resource to be closed.',
            );
        }

        return $this;
    }
}

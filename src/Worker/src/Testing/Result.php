<?php

declare(strict_types=1);

namespace Phalanx\Worker\Testing;

use PHPUnit\Framework\Assert;

final readonly class Result
{
    public function __construct(
        public mixed $value,
        public int $liveTasks,
        public int $liveRuntimeScopes,
    ) {
    }

    public function assertValueSame(mixed $expected): self
    {
        Assert::assertSame($expected, $this->value);

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

    public function assertNoLiveRuntimeScopes(): self
    {
        Assert::assertSame(
            0,
            $this->liveRuntimeScopes,
            "Expected no live runtime scopes; {$this->liveRuntimeScopes} still live.",
        );

        return $this;
    }
}

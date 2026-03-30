<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use React\Promise\Deferred;

use function React\Async\await;

final class SingleflightGroup
{
    /** @var array<string, Deferred> */
    private array $inFlight = [];

    public function do(string $key, callable $execute): mixed
    {
        if (isset($this->inFlight[$key])) {
            return await($this->inFlight[$key]->promise());
        }

        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred;

        try {
            $result = $execute();
            $deferred->resolve($result);

            return $result;
        } catch (\Throwable $e) {
            $deferred->reject($e);

            throw $e;
        } finally {
            unset($this->inFlight[$key]);
        }
    }

    public function pending(): int
    {
        return count($this->inFlight);
    }
}


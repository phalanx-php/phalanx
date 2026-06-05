<?php

declare(strict_types=1);

namespace Phalanx\Agents\Activity;

use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Stream;
use ReflectionClass;

final class Result
{
    private(set) string $activityId;

    private(set) Stream $stream;

    public State $state {
        get => $this->resolve()->state;
    }

    public Outcome $outcome {
        get => $this->resolve()->outcome;
    }

    public Log $log {
        get => $this->resolve()->log;
    }

    public int $invocations {
        get => $this->resolve()->invocations;
    }

    public ?\Throwable $error {
        get => $this->resolve()->error;
    }

    /** @var ?ReflectionClass<self> */
    private static ?ReflectionClass $reflection = null;

    private ?TerminalState $resolved = null;

    private ?TerminalCell $cell = null;

    public function __construct(
        string $activityId,
        State $state,
        Outcome $outcome,
        Log $log,
        int $invocations,
        ?\Throwable $error = null,
        ?Stream $stream = null,
    ) {
        if ($activityId === '') {
            throw new \InvalidArgumentException('Activity id cannot be empty.');
        }

        if ($invocations < 0) {
            throw new \InvalidArgumentException('Activity invocation count must be >= 0.');
        }

        $this->activityId = $activityId;
        $this->stream = $stream ?? Stream::from([]);
        $this->resolved = new TerminalState($state, $outcome, $log, $invocations, $error);
    }

    public static function lazy(string $activityId, Stream $stream, TerminalCell $cell): self
    {
        self::$reflection ??= new ReflectionClass(self::class);

        /** @var self $instance */
        $instance = self::$reflection->newInstanceWithoutConstructor();
        $instance->activityId = $activityId;
        $instance->stream = $stream;
        $instance->cell = $cell;

        return $instance;
    }

    private function resolve(): TerminalState
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $value = $this->cell?->value;

        if (!$value instanceof TerminalState) {
            throw new \RuntimeException(
                'Terminal state not yet available — the stream must be fully consumed before accessing result properties',
            );
        }

        $this->resolved = $value;
        $this->cell = null;

        return $this->resolved;
    }
}

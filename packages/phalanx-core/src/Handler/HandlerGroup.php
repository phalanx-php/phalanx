<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use RuntimeException;

/**
 * Self-dispatching handler collection.
 *
 * HandlerGroup implements Executable, reading scope attributes to determine
 * which handler to invoke:
 *
 * - 'handler.key' (string) -> direct lookup
 * - Registered matchers -> protocol-specific matching (routes, commands, etc.)
 *
 * Runners become thin shells that set attributes and execute the group.
 */
final class HandlerGroup implements Executable
{
    /**
     * @param array<string, Handler> $handlers
     * @param list<Scopeable|Executable> $middleware
     * @param list<HandlerMatcher> $matchers
     */
    private function __construct(
        public private(set) array $handlers,
        public private(set) array $middleware = [],
        public private(set) array $matchers = [],
    ) {
    }

    /**
     * Create from an array of handlers.
     *
     * @param array<string, Handler|Scopeable|Executable> $handlers
     */
    public static function of(array $handlers): self
    {
        $normalized = [];

        foreach ($handlers as $key => $handler) {
            if ($handler instanceof Handler) {
                $normalized[$key] = $handler;
            } else {
                $normalized[$key] = Handler::of($handler);
            }
        }

        return new self($normalized);
    }

    public static function create(): self
    {
        return new self([]);
    }

    /**
     * Add a handler with explicit key.
     */
    public function add(string $key, Handler $handler): self
    {
        return new self(
            [...$this->handlers, $key => $handler],
            $this->middleware,
            $this->matchers,
        );
    }

    /**
     * Merge another group into this one.
     *
     * Handlers from $other override handlers with the same key.
     */
    public function merge(self $other): self
    {
        return new self(
            [...$this->handlers, ...$other->handlers],
            [...$this->middleware, ...$other->middleware],
            [...$this->matchers, ...$other->matchers],
        );
    }

    /**
     * Wrap all handlers with middleware.
     *
     * Middleware runs in order: first added runs first.
     */
    public function wrap(Scopeable|Executable ...$middleware): self
    {
        return new self(
            $this->handlers,
            array_values([...$this->middleware, ...$middleware]),
            $this->matchers,
        );
    }

    /**
     * Register matchers for protocol-specific dispatch.
     */
    public function withMatcher(HandlerMatcher ...$matchers): self
    {
        return new self(
            $this->handlers,
            $this->middleware,
            array_values([...$this->matchers, ...$matchers]),
        );
    }

    /**
     * Get all handler keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Get a handler by key.
     */
    public function get(string $key): ?Handler
    {
        return $this->handlers[$key] ?? null;
    }

    /**
     * Get all handlers.
     *
     * @return array<string, Handler>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * Filter handlers by config type.
     *
     * @param class-string<HandlerConfig> $configClass
     * @return array<string, Handler>
     */
    public function filterByConfig(string $configClass): array
    {
        return array_filter(
            $this->handlers,
            static fn(Handler $h): bool => $h->config instanceof $configClass,
        );
    }

    private function dispatchByKey(ExecutionScope $scope): mixed
    {
        $key = $scope->attribute('handler.key');
        $handler = $this->handlers[$key] ?? null;

        if ($handler === null) {
            throw new RuntimeException("Handler not found: $key");
        }

        return $this->executeHandler($handler, $scope);
    }

    private function executeHandler(Handler $handler, ExecutionScope $scope): mixed
    {
        $task = $handler->task;

        $allMiddleware = [...$this->middleware, ...$handler->config->middleware];

        if ($allMiddleware !== []) {
            $task = new MiddlewareWrapper($task, $allMiddleware);
        }

        return $task($scope);
    }

    /**
     * Dispatch to the appropriate handler based on scope attributes.
     */
    public function __invoke(ExecutionScope $scope): mixed
    {
        if ($scope->attribute('handler.key') !== null) {
            return $this->dispatchByKey($scope);
        }

        foreach ($this->matchers as $matcher) {
            $result = $matcher->match($scope, $this->handlers);

            if ($result !== null) {
                return $this->executeHandler($result->handler, $result->scope);
            }
        }

        throw new RuntimeException(
            'HandlerGroup: no matcher could handle this scope. '
            . 'Register matchers via withMatcher() or set handler.key attribute.'
        );
    }
}

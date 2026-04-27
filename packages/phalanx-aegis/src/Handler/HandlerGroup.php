<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Closure;
use Phalanx\ExecutionScope;
use Phalanx\HasMiddleware;
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
 *
 * Middleware composition order at dispatch:
 *   group (outermost) -> handler-config -> handler-instance HasMiddleware (innermost)
 * Class-string identity is used to deduplicate; if the same middleware
 * class-string appears at multiple levels, the innermost declaration wins.
 *
 * Protocol-specific groups (Route, Command, WsRoute) install an `invoker`
 * closure via `withInvoker()` to translate the scope-only dispatch into
 * additional argument shapes (e.g. HTTP input hydration). The default
 * invoker simply calls `$instance($scope)`.
 */
final class HandlerGroup implements Executable
{
    /**
     * @param array<string, Handler> $handlers
     * @param list<class-string> $middleware
     * @param list<HandlerMatcher> $matchers
     * @param Closure(Scopeable|Executable, ExecutionScope): mixed|null $invoker
     */
    private function __construct(
        public private(set) array $handlers,
        public private(set) array $middleware = [],
        public private(set) array $matchers = [],
        private ?Closure $invoker = null,
    ) {
    }

    /**
     * @internal
     * @param array<string, Handler|class-string<Scopeable|Executable>> $handlers
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

    /** @internal */
    public static function create(): self
    {
        return new self([]);
    }

    /** @internal */
    public function add(string $key, Handler $handler): self
    {
        return new self(
            [...$this->handlers, $key => $handler],
            $this->middleware,
            $this->matchers,
            $this->invoker,
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
            $this->invoker ?? $other->invoker,
        );
    }

    /**
     * Wrap all handlers in this group with middleware (outermost layer).
     *
     * @param class-string ...$middleware
     */
    public function wrap(string ...$middleware): self
    {
        return new self(
            $this->handlers,
            array_values([...$this->middleware, ...$middleware]),
            $this->matchers,
            $this->invoker,
        );
    }

    public function withMatcher(HandlerMatcher ...$matchers): self
    {
        return new self(
            $this->handlers,
            $this->middleware,
            array_values([...$this->matchers, ...$matchers]),
            $this->invoker,
        );
    }

    /**
     * Install an invocation strategy for resolved handler instances.
     *
     * @param Closure(Scopeable|Executable, ExecutionScope): mixed $invoker
     */
    public function withInvoker(Closure $invoker): self
    {
        return new self(
            $this->handlers,
            $this->middleware,
            $this->matchers,
            $invoker,
        );
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }

    public function get(string $key): ?Handler
    {
        return $this->handlers[$key] ?? null;
    }

    /** @return array<string, Handler> */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
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
        /** @var HandlerResolver $resolver */
        $resolver = $scope->service(HandlerResolver::class);
        $instance = $resolver->resolve($handler->task, $scope);

        $invoker = $this->invoker ?? self::defaultInvoker();

        // Three-layer middleware composition (outermost first):
        //   group -> handler-config -> handler-instance HasMiddleware
        // Dedup keeps the LAST occurrence so the innermost declaration wins.
        $instanceMiddleware = $instance instanceof HasMiddleware ? $instance->middleware : [];

        $combined = self::dedupMiddleware([
            ...$this->middleware,
            ...$handler->config->middleware,
            ...$instanceMiddleware,
        ]);

        if ($combined === []) {
            return $invoker($instance, $scope);
        }

        $resolved = [];
        foreach ($combined as $cs) {
            /** @var class-string<Scopeable|Executable> $cs */
            $resolved[] = $resolver->resolve($cs, $scope);
        }

        $terminal = new HandlerInvocationAdapter($instance, $invoker);

        return (new MiddlewareWrapper($terminal, $resolved))($scope);
    }

    /**
     * @return Closure(Scopeable|Executable, ExecutionScope): mixed
     */
    private static function defaultInvoker(): Closure
    {
        return static fn(Scopeable|Executable $instance, ExecutionScope $scope): mixed => $instance($scope);
    }

    /**
     * Deduplicate middleware class-strings keeping the LAST occurrence
     * (innermost declaration wins). The chain is then walked in original
     * order so the surviving entry runs at its innermost position.
     *
     * @param list<class-string> $middleware
     * @return list<class-string>
     */
    private static function dedupMiddleware(array $middleware): array
    {
        return array_values(array_reverse(
            array_unique(array_reverse($middleware))
        ));
    }
}

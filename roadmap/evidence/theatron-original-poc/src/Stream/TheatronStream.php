<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Theatron\Reactive\DirtyBatch;
use ReflectionFunction;

final class TheatronStream
{
    private Channel $intake;
    private bool $running = false;
    private int $nextId = 0;

    /** @var array<class-string<StreamEvent>, array<int, Closure>> */
    private array $subscribers = [];

    /** @var ?Closure(class-string<StreamEvent>, int): void */
    private ?Closure $traceHook = null;

    public function __construct(
        private ?DirtyBatch $dirty = null,
    ) {
        $this->intake = new Channel(256);
    }

    /** @param Closure(class-string<StreamEvent>, int): void $hook */
    public function onTrace(Closure $hook): void
    {
        $this->traceHook = $hook;
    }

    public function emit(StreamEvent $event): void
    {
        if (!$this->intake->isOpen) {
            return;
        }

        if (!$this->intake->tryEmit($event)) {
            $this->intake->emit($event);
        }
    }

    /**
     * @template T of StreamEvent
     * @param class-string<T> $eventClass
     * @param Closure(T): void $handler
     */
    public function subscribe(string $eventClass, Closure $handler): StreamSubscription
    {
        self::assertStaticClosure($handler);

        $id = $this->nextId++;
        $this->subscribers[$eventClass][$id] = $handler;

        $subscribers = &$this->subscribers;

        return new StreamSubscription(static function () use (&$subscribers, $eventClass, $id): void {
            unset($subscribers[$eventClass][$id]);

            if (empty($subscribers[$eventClass])) {
                unset($subscribers[$eventClass]);
            }
        });
    }

    public function start(ExecutionScope $scope): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $intake = $this->intake;
        $subscribers = &$this->subscribers;
        $dirty = $this->dirty;
        $running = &$this->running;
        $traceHook = $this->traceHook;

        $scope->go(static function () use ($intake, &$subscribers, $dirty, &$running, $traceHook): void {
            try {
                foreach ($intake->consume() as $event) {
                    $count = self::dispatch($event, $subscribers, $dirty);

                    if ($traceHook !== null && $count > 0) {
                        $traceHook($event::class, $count);
                    }
                }
            } finally {
                $running = false;
            }
        });
    }

    public function stop(): void
    {
        $this->intake->complete();
    }

    /**
     * @param array<class-string<StreamEvent>, array<int, Closure>> $subscribers
     * @return int Number of handlers invoked
     */
    private static function dispatch(StreamEvent $event, array &$subscribers, ?DirtyBatch $dirty): int
    {
        $count = 0;

        foreach ($subscribers as $class => $handlers) {
            if (!$event instanceof $class) {
                continue;
            }

            foreach ($handlers as $handler) {
                $handler($event);
                $count++;
            }
        }

        if ($count > 0) {
            $dirty?->request();
        }

        return $count;
    }

    private static function assertStaticClosure(Closure $fn): void
    {
        $ref = new ReflectionFunction($fn);

        if ($ref->getClosureThis() !== null) {
            throw new \LogicException(
                'TheatronStream subscribers must use static closures to prevent reference-cycle leaks in long-running processes.',
            );
        }
    }
}

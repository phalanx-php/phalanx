<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Message;
use Phalanx\Scope\ExecutionScope;
use ReflectionFunction;
use RuntimeException;

final readonly class Turn
{
    /**
     * @param list<Message> $pendingMessages
     * @param array<string, Closure> $hooks
     * @param ?Closure(StepResult, ExecutionScope): StepAction $onStepHook
     */
    private function __construct(
        public AgentDefinition $agent,
        public ?Conversation $conversation,
        public array $pendingMessages,
        public int $maxSteps,
        public ?string $outputClass,
        public array $hooks,
        public bool $streaming,
        public ?Closure $onStepHook,
    ) {}

    public static function begin(AgentDefinition $agent): self
    {
        return new self(
            agent: $agent,
            conversation: null,
            pendingMessages: [],
            maxSteps: 10,
            outputClass: null,
            hooks: [],
            streaming: false,
            onStepHook: null,
        );
    }

    public function message(string|Message $message): self
    {
        $msg = is_string($message) ? Message::user($message) : $message;

        return new self(
            $this->agent,
            $this->conversation,
            [...$this->pendingMessages, $msg],
            $this->maxSteps,
            $this->outputClass,
            $this->hooks,
            $this->streaming,
            $this->onStepHook,
        );
    }

    public function conversation(Conversation $conversation): self
    {
        return new self(
            $this->agent,
            $conversation,
            $this->pendingMessages,
            $this->maxSteps,
            $this->outputClass,
            $this->hooks,
            $this->streaming,
            $this->onStepHook,
        );
    }

    public function maxSteps(int $maxSteps): self
    {
        return new self(
            $this->agent,
            $this->conversation,
            $this->pendingMessages,
            $maxSteps,
            $this->outputClass,
            $this->hooks,
            $this->streaming,
            $this->onStepHook,
        );
    }

    /** @param class-string $class */
    public function output(string $class): self
    {
        return new self(
            $this->agent,
            $this->conversation,
            $this->pendingMessages,
            $this->maxSteps,
            $class,
            $this->hooks,
            $this->streaming,
            $this->onStepHook,
        );
    }

    public function stream(): self
    {
        return new self(
            $this->agent,
            $this->conversation,
            $this->pendingMessages,
            $this->maxSteps,
            $this->outputClass,
            $this->hooks,
            true,
            $this->onStepHook,
        );
    }

    public function on(AgentEventKind $kind, Closure $handler): self
    {
        self::requireStatic($handler, "Turn::on({$kind->value})");
        $hooks = $this->hooks;
        $hooks[$kind->value] = $handler;

        return new self(
            $this->agent,
            $this->conversation,
            $this->pendingMessages,
            $this->maxSteps,
            $this->outputClass,
            $hooks,
            $this->streaming,
            $this->onStepHook,
        );
    }

    /** @param Closure(StepResult, ExecutionScope): StepAction $callback */
    public function onStep(Closure $callback): self
    {
        self::requireStatic($callback, 'Turn::onStep()');
        return new self(
            $this->agent,
            $this->conversation,
            $this->pendingMessages,
            $this->maxSteps,
            $this->outputClass,
            $this->hooks,
            $this->streaming,
            $callback,
        );
    }

    private static function requireStatic(Closure $closure, string $context): void
    {
        if (!(new ReflectionFunction($closure))->isStatic()) {
            throw new RuntimeException(
                "{$context} requires a static closure. Non-static closures capture \$this and "
                . 'leak in long-running coroutines.',
            );
        }
    }

    public function buildConversation(): Conversation
    {
        $conv = $this->conversation ?? Conversation::create();

        if ($conv->systemPrompt === null) {
            $conv = $conv->system($this->agent->instructions);
        }

        foreach ($this->pendingMessages as $msg) {
            $conv = $conv->append($msg);
        }

        return $conv;
    }
}

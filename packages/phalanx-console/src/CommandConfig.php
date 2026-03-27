<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Handler\HandlerConfig;

/**
 * Console command configuration.
 */
final class CommandConfig extends HandlerConfig
{
    /**
     * @param list<CommandArgument> $arguments
     * @param list<CommandOption> $options
     * @param list<CommandValidator> $validators
     * @param list<string> $tags
     */
    public function __construct(
        public private(set) string $description = '',
        public private(set) array $arguments = [],
        public private(set) array $options = [],
        public private(set) array $validators = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct($tags, $priority);
    }

    public function withDescription(string $description): self
    {
        $clone = clone $this;
        $clone->description = $description;
        return $clone;
    }

    public function withArgument(
        string $name,
        string $description = '',
        bool $required = true,
        mixed $default = null,
    ): self {
        $clone = clone $this;
        $clone->arguments = [...$this->arguments, new CommandArgument($name, $description, $required, $default)];
        return $clone;
    }

    public function withOption(
        string $name,
        string $shorthand = '',
        string $description = '',
        bool $requiresValue = false,
        mixed $default = null,
    ): self {
        $clone = clone $this;
        $clone->options = [...$this->options, new CommandOption($name, $shorthand, $description, $requiresValue, $default)];
        return $clone;
    }

    public function withValidator(CommandValidator $validator): self
    {
        $clone = clone $this;
        $clone->validators = [...$this->validators, $validator];
        return $clone;
    }

    #[\Override]
    public function withTags(string ...$tags): static
    {
        $clone = clone $this;
        $clone->tags = array_values([...$this->tags, ...$tags]);
        return $clone;
    }

    #[\Override]
    public function withPriority(int $priority): static
    {
        $clone = clone $this;
        $clone->priority = $priority;
        return $clone;
    }
}

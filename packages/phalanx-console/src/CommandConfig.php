<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Handler\HandlerConfig;

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

<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Handler\HandlerConfig;

final class CommandConfig extends HandlerConfig
{
    /**
     * @param list<CommandArgument> $arguments
     * @param list<CommandOption> $options
     * @param list<string> $tags
     */
    public function __construct(
        private(set) string $description = '',
        private(set) array $arguments = [],
        private(set) array $options = [],
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

<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Composer;

final class SlashCommandRegistry
{
    /** @var array<string, callable> */
    private array $commands = [];

    public function register(string $command, callable $handler): void
    {
        $this->commands[$command] = $handler;
    }

    public function dispatch(string $input, array $context = []): mixed
    {
        if (str_starts_with($input, '/')) {
            $parts = explode(' ', ltrim($input, '/'), 2);
            $cmd = $parts[0];
            $args = $parts[1] ?? '';

            if (isset($this->commands[$cmd])) {
                return ($this->commands[$cmd])($args, $context);
            }
        }
        return null; // not a slash command
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Archon\Application;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Boot\AppContext;

final readonly class ConsoleConfig
{
    /**
     * @param list<string> $argv
     */
    public function __construct(
        public array $argv = [],
        public string $defaultCommand = 'help',
        public string $scriptName = 'archon',
        public ?StreamOutput $output = null,
        public ?StreamOutput $errorOutput = null,
        public ?TerminalEnvironment $terminal = null,
        public ?ConsoleSignalPolicy $signalPolicy = null,
    ) {
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            argv: self::argvFromContext($context),
            terminal: TerminalEnvironment::fromContext($context),
            signalPolicy: ConsoleSignalPolicy::default(),
        );
    }

    public function signalPolicy(): ConsoleSignalPolicy
    {
        return $this->signalPolicy ?? ConsoleSignalPolicy::default();
    }

    /** @param list<string> $argv */
    public function withArgv(array $argv): self
    {
        return new self(
            argv: [...$argv],
            defaultCommand: $this->defaultCommand,
            scriptName: $this->scriptName,
            output: $this->output,
            errorOutput: $this->errorOutput,
            terminal: $this->terminal,
            signalPolicy: $this->signalPolicy,
        );
    }

    public function withDefaultCommand(string $command): self
    {
        return new self(
            argv: $this->argv,
            defaultCommand: $command,
            scriptName: $this->scriptName,
            output: $this->output,
            errorOutput: $this->errorOutput,
            terminal: $this->terminal,
            signalPolicy: $this->signalPolicy,
        );
    }

    /** @return list<string> */
    private static function argvFromContext(AppContext $context): array
    {
        $argv = $context->get('argv', []);

        if (!is_array($argv)) {
            return [];
        }

        $argv = array_values(array_filter(
            $argv,
            is_string(...),
        ));

        /** @var list<string> $args */
        $args = array_slice($argv, 1);

        return $args;
    }
}

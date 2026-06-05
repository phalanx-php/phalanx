<?php

declare(strict_types=1);

namespace Phalanx\Console\Application;

use Phalanx\Boot\AppContext;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Console\Runtime\Identity\SignalPolicy;

final readonly class Config
{
    /**
     * @param list<string> $argv
     */
    public function __construct(
        public array $argv = [],
        public string $defaultCommand = 'help',
        public string $scriptName = 'console',
        public ?StreamOutput $output = null,
        public ?StreamOutput $errorOutput = null,
        public ?TerminalEnvironment $terminal = null,
        public ?SignalPolicy $signalPolicy = null,
    ) {
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            argv: self::argvFromContext($context),
            terminal: TerminalEnvironment::fromContext($context),
            signalPolicy: SignalPolicy::default(),
        );
    }

    public function signalPolicy(): SignalPolicy
    {
        return $this->signalPolicy ?? SignalPolicy::default();
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

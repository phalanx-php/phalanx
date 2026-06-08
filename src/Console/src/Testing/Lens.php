<?php

declare(strict_types=1);

namespace Phalanx\Console\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Console\Application\Application;
use Phalanx\Console\Application\Config;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Console;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Console\Runtime\ConsoleResourceSid;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Service\ServiceBundle;
use Phalanx\Stream\Stream;
use Phalanx\Testing\Attribute\Lens as LensAttribute;
use Phalanx\Testing\Lens as LensContract;
use RuntimeException;

/**
 * Console test lens for an Console application.
 *
 * Each run() builds a fresh Application wired against captured stdout
 * and a /dev/null-backed key reader, dispatches the supplied argv, and
 * returns a Result.
 *
 * Per-run isolation is intentional: command dispatch carries side effects
 * (signal handlers, process lifecycle, scoped key buffers) that don't
 * compose well across multiple invocations of the same Console host. The
 * cost of rebuilding is bounded; each test typically runs one command.
 *
 * Usage:
 *
 *     $app = $this->testApp($context, new TestableBundle());
 *     $app->console
 *         ->commands(CommandGroup::of(['greet' => GreetCommand::class]))
 *         ->run(['greet', 'Ada'])
 *         ->assertSuccessful()
 *         ->assertOutputContains('Hello, Ada');
 */
#[LensAttribute(
    accessor: 'console',
    returns: self::class,
    factory: LensFactory::class,
    requires: [],
)]
final class Lens implements LensContract
{
    private AppContext $context;

    private ?CommandGroup $commands = null;

    /** @var list<ServiceBundle> */
    private array $providers = [];

    private ?Config $config = null;

    public function __construct()
    {
        $this->context = new AppContext();
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        $this->context = new AppContext($context);

        return $this;
    }

    public function commands(CommandGroup $commands): self
    {
        $this->commands = $commands;

        return $this;
    }

    public function withProviders(ServiceBundle ...$providers): self
    {
        $this->providers = array_values([...$this->providers, ...$providers]);

        return $this;
    }

    public function withConfig(Config $config): self
    {
        $this->config = $config;

        return $this;
    }

    /** @param list<string> $argv */
    public function run(array $argv): Result
    {
        if ($this->commands === null) {
            throw new RuntimeException(
                'Lens::run() requires commands(CommandGroup) to be set first.',
            );
        }

        $stdout = Stream::captureBuffer();
        $stderr = Stream::captureBuffer();
        $capturedOutput = new StreamOutput(
            stream: $stdout->resource(),
            terminal: new TerminalEnvironment(columns: 80, lines: 24, isTty: false),
        );
        $nullInput = Stream::nullInput();

        $console = $this->buildConsole($capturedOutput, $nullInput->resource());

        try {
            $exitCode = $console->dispatch($argv);
            $memory = $console->host()->runtime()->memory;
            $supervisor = $console->host()->supervisor();

            return new Result(
                exitCode: $exitCode,
                stdout: $stdout->contents(),
                stderr: $stderr->contents(),
                liveCommandResources: $memory->resources->liveCount(ConsoleResourceSid::Command),
                liveRuntimeScopes: $memory->resources->liveCount(RuntimeResourceSid::Scope),
                liveTasks: $supervisor->liveCount(),
                commandResourceStates: array_map(
                    static fn(ManagedResource $resource) => $resource->state,
                    $memory->resources->all(ConsoleResourceSid::Command),
                ),
            );
        } finally {
            $console->shutdown();
            $stdout->close();
            $stderr->close();
            $nullInput->close();
        }
    }

    public function reset(): void
    {
        $this->context = new AppContext();
        $this->commands = null;
        $this->providers = [];
        $this->config = null;
    }

    /** @param resource $nullInput */
    private function buildConsole(StreamOutput $capturedOutput, mixed $nullInput): Application
    {
        $captureBundle = new CaptureBundle($capturedOutput, $nullInput);

        $builder = Console::starting($this->context->values)
            ->providers($captureBundle, ...$this->providers)
            ->commands($this->commands ?? CommandGroup::of([]));

        if ($this->config !== null) {
            $builder = $builder->withConfig($this->config);
        }

        return $builder->build();
    }
}

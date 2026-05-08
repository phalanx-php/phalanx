<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Application\ArchonApplication;
use Phalanx\Archon\Application\ConsoleConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestLens as TestLensContract;
use RuntimeException;

/**
 * Console test lens for an Archon application.
 *
 * Each run() builds a fresh ArchonApplication wired against captured stdout
 * and a /dev/null-backed key reader, dispatches the supplied argv, and
 * returns a ConsoleResult.
 *
 * Per-run isolation is intentional: command dispatch carries side effects
 * (signal handlers, process lifecycle, scoped key buffers) that don't
 * compose well across multiple invocations of the same Archon host. The
 * cost of rebuilding is bounded; each test typically runs one command.
 *
 * Usage:
 *
 *     $app = $this->testApp($context, new ArchonTestableBundle());
 *     $app->console
 *         ->commands(CommandGroup::of(['greet' => [GreetCommand::class, ...]]))
 *         ->run(['greet', 'Ada'])
 *         ->assertSuccessful()
 *         ->assertOutputContains('Hello, Ada');
 */
#[TestLens(
    accessor: 'console',
    returns: self::class,
    factory: ConsoleLensFactory::class,
    requires: [],
)]
final class ConsoleLens implements TestLensContract
{
    /** @var array<string, mixed> */
    private array $context = [];

    private ?CommandGroup $commands = null;

    /** @var list<ServiceBundle> */
    private array $providers = [];

    private ?ConsoleConfig $consoleConfig = null;

    public function __construct()
    {
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        $this->context = $context;

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

    public function withConsoleConfig(ConsoleConfig $config): self
    {
        $this->consoleConfig = $config;

        return $this;
    }

    /** @param list<string> $argv */
    public function run(array $argv): ConsoleResult
    {
        if ($this->commands === null) {
            throw new RuntimeException(
                'ConsoleLens::run() requires commands(CommandGroup) to be set first.',
            );
        }

        $stdout = self::openCaptureStream();
        $stderr = self::openCaptureStream();
        $capturedOutput = new StreamOutput(
            stream: $stdout,
            terminal: new TerminalEnvironment(columns: 80, lines: 24, isTty: false),
        );
        $nullInput = self::openNullInput();

        $archon = $this->buildArchon($capturedOutput, $nullInput);

        try {
            $exitCode = $archon->dispatch($argv);

            return new ConsoleResult(
                exitCode: $exitCode,
                stdout: self::drain($stdout),
                stderr: self::drain($stderr),
            );
        } finally {
            $archon->shutdown();
            self::close($stdout);
            self::close($stderr);
            self::close($nullInput);
        }
    }

    public function reset(): void
    {
        $this->context = [];
        $this->commands = null;
        $this->providers = [];
        $this->consoleConfig = null;
    }

    /** @return resource */
    private static function openCaptureStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open capture stream for ConsoleLens.');
        }

        return $stream;
    }

    /** @return resource */
    private static function openNullInput(): mixed
    {
        $stream = fopen('/dev/null', 'r');

        if ($stream === false) {
            throw new RuntimeException('Unable to open /dev/null for ConsoleLens key input.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function drain(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @param resource $stream */
    private static function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** @param resource $nullInput */
    private function buildArchon(StreamOutput $capturedOutput, mixed $nullInput): ArchonApplication
    {
        $captureBundle = new ConsoleCaptureBundle($capturedOutput, $nullInput);

        $builder = Archon::starting($this->context)
            ->providers($captureBundle, ...$this->providers)
            ->commands($this->commands ?? CommandGroup::of([]));

        if ($this->consoleConfig !== null) {
            $builder = $builder->withConsoleConfig($this->consoleConfig);
        }

        return $builder->build();
    }
}

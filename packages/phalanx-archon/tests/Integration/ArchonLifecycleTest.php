<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Archon\Archon;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleConfig;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Output\TerminalEnvironment;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArchonLifecycleTest extends TestCase
{
    #[Test]
    public function disposesHandlerScopeAfterSuccessfulCommand(): void
    {
        $app = Archon::starting()
            ->commands(CommandGroup::of([
                'lifecycle' => LifecycleCommand::class,
            ]))
            ->build();

        $code = $app->dispatch(['lifecycle']);

        self::assertSame(0, $code);
        self::assertTrue(LifecycleCommand::$disposed);
        $app->shutdown();
    }

    #[Test]
    public function disposesHandlerScopeAfterThrownCommand(): void
    {
        $stream = self::outputStream();
        $app = Archon::starting()
            ->commands(CommandGroup::of([
                'fail' => ThrowingLifecycleCommand::class,
            ]))
            ->withConsoleConfig(new ConsoleConfig(errorOutput: self::streamOutput($stream)))
            ->build();

        $code = $app->dispatch(['fail']);

        self::assertSame(1, $code);
        self::assertTrue(ThrowingLifecycleCommand::$disposed);
        self::assertStringContainsString('expected failure', self::streamContents($stream));
        $app->shutdown();
    }

    #[Test]
    public function disposesLoaderScopeAndLoadedCommandScope(): void
    {
        $dir = $this->makeCommandDirectory();

        $app = Archon::starting()
            ->commands($dir)
            ->build();

        self::assertTrue(LoadedLifecycleState::$loadScopeDisposed);

        $code = $app->dispatch(['loaded']);

        self::assertSame(0, $code);
        self::assertTrue(LoadedLifecycleState::$executionScopeDisposed);
        $app->shutdown();
    }

    protected function setUp(): void
    {
        LifecycleCommand::$disposed = false;
        ThrowingLifecycleCommand::$disposed = false;
        LoadedLifecycleState::$loadScopeDisposed = false;
        LoadedLifecycleState::$executionScopeDisposed = false;
    }

    private function makeCommandDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('phalanx-archon-', true);

        if (!mkdir($dir) && !is_dir($dir)) {
            self::fail("Unable to create command directory: $dir");
        }

        file_put_contents($dir . '/commands.php', <<<'PHP'
<?php

declare(strict_types=1);

return static function (\Phalanx\Scope\ExecutionScope $scope): \Phalanx\Archon\CommandGroup {
    $scope->onDispose(static function (): void {
        \Phalanx\Archon\Tests\Integration\LoadedLifecycleState::$loadScopeDisposed = true;
    });

    return \Phalanx\Archon\CommandGroup::of([
        'loaded' => \Phalanx\Archon\Tests\Integration\LoadedLifecycleCommand::class,
    ]);
};
PHP);

        return $dir;
    }

    /** @return resource */
    private static function outputStream(): mixed
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function streamOutput(mixed $stream): StreamOutput
    {
        return new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    }

    /** @param resource $stream */
    private static function streamContents(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }
}

final class LifecycleCommand implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(CommandScope $scope): int
    {
        $scope->onDispose(static function (): void {
            self::$disposed = true;
        });

        return 0;
    }
}

final class ThrowingLifecycleCommand implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(CommandScope $scope): int
    {
        $scope->onDispose(static function (): void {
            self::$disposed = true;
        });

        throw new RuntimeException('expected failure');
    }
}

final class LoadedLifecycleCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $scope->onDispose(static function (): void {
            LoadedLifecycleState::$executionScopeDisposed = true;
        });

        return 0;
    }
}

final class LoadedLifecycleState
{
    public static bool $loadScopeDisposed = false;

    public static bool $executionScopeDisposed = false;
}

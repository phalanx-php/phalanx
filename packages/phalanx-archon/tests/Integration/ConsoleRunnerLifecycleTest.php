<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Application;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsoleRunnerLifecycleTest extends TestCase
{
    #[Test]
    public function disposesHandlerScopeAfterSuccessfulCommand(): void
    {
        $app = Application::starting()->compile();
        $runner = ConsoleRunner::withHandlers($app, CommandGroup::of([
            'lifecycle' => LifecycleCommand::class,
        ]));

        $code = $runner->run(['cli', 'lifecycle']);

        self::assertSame(0, $code);
        self::assertTrue(LifecycleCommand::$disposed);
    }

    #[Test]
    public function disposesHandlerScopeAfterThrownCommand(): void
    {
        $app = Application::starting()->compile();
        $runner = ConsoleRunner::withHandlers($app, CommandGroup::of([
            'fail' => ThrowingLifecycleCommand::class,
        ]));

        ob_start();
        $code = $runner->run(['cli', 'fail']);
        ob_end_clean();

        self::assertSame(1, $code);
        self::assertTrue(ThrowingLifecycleCommand::$disposed);
    }

    #[Test]
    public function disposesLegacyCommandScope(): void
    {
        $app = Application::starting()->compile();
        $runner = (new ConsoleRunner($app))->withCommand('legacy', new LegacyLifecycleCommand());

        $code = $runner->run(['cli', 'legacy']);

        self::assertSame(0, $code);
        self::assertTrue(LegacyLifecycleCommand::$disposed);
    }

    #[Test]
    public function disposesLoaderScopeAndLoadedCommandScope(): void
    {
        $dir = $this->makeCommandDirectory();

        $app = Application::starting()->compile();
        $runner = ConsoleRunner::withCommands($app, $dir);

        self::assertTrue(LoadedLifecycleState::$loadScopeDisposed);

        $code = $runner->run(['cli', 'loaded']);

        self::assertSame(0, $code);
        self::assertTrue(LoadedLifecycleState::$executionScopeDisposed);
    }

    protected function setUp(): void
    {
        LifecycleCommand::$disposed = false;
        ThrowingLifecycleCommand::$disposed = false;
        LegacyLifecycleCommand::$disposed = false;
        LoadedLifecycleState::$loadScopeDisposed = false;
        LoadedLifecycleState::$executionScopeDisposed = false;
    }

    private function makeCommandDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/phalanx-archon-' . bin2hex(random_bytes(6));

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

final class LegacyLifecycleCommand implements Executable
{
    public static bool $disposed = false;

    public function __invoke(ExecutionScope $scope): int
    {
        $scope->onDispose(static function (): void {
            self::$disposed = true;
        });

        return 0;
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

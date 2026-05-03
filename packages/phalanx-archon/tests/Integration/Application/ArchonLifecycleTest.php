<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Application;

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Application\ConsoleConfig;
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
        $stream = StreamOutputHelper::open();
        $app = Archon::starting()
            ->commands(CommandGroup::of([
                'fail' => ThrowingLifecycleCommand::class,
            ]))
            ->withConsoleConfig(new ConsoleConfig(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['fail']);

        self::assertSame(1, $code);
        self::assertTrue(ThrowingLifecycleCommand::$disposed);
        self::assertStringContainsString('expected failure', StreamOutputHelper::contents($stream));
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

return static function (\Phalanx\Scope\ExecutionScope $scope): \Phalanx\Archon\Command\CommandGroup {
    $scope->onDispose(static function (): void {
        \Phalanx\Archon\Tests\Integration\Application\LoadedLifecycleState::$loadScopeDisposed = true;
    });

    return \Phalanx\Archon\Command\CommandGroup::of([
        'loaded' => \Phalanx\Archon\Tests\Integration\Application\LoadedLifecycleCommand::class,
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

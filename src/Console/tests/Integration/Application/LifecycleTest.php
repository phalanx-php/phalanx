<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Tests\Support\TestCase;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Application\Config;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class LifecycleTest extends TestCase
{
    #[Test]
    public function disposesHandlerScopeAfterSuccessfulCommand(): void
    {
        $app = self::console()
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
        $app = self::console()
            ->commands(CommandGroup::of([
                'fail' => ThrowingLifecycleCommand::class,
            ]))
            ->withConfig(new Config(errorOutput: StreamOutputHelper::output($stream)))
            ->build();

        $code = $app->dispatch(['fail']);

        self::assertSame(1, $code);
        self::assertTrue(ThrowingLifecycleCommand::$disposed);
        self::assertStringContainsString('expected failure', StreamOutputHelper::contents($stream));
        $app->shutdown();
    }

    #[Test]
    public function disposesLoaderScopeAndLoadedCommandContext(): void
    {
        $dir = $this->makeCommandDirectory();

        $app = self::console()
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
        $dir = sys_get_temp_dir() . '/' . uniqid('phalanx-console-', true);

        if (!mkdir($dir) && !is_dir($dir)) {
            self::fail("Unable to create command directory: $dir");
        }

        file_put_contents($dir . '/commands.php', <<<'PHP'
<?php

declare(strict_types=1);

return static function (\Phalanx\Scope\ExecutionScope $scope): \Phalanx\Console\Command\CommandGroup {
    $scope->onDispose(static function (): void {
        \Phalanx\Console\Tests\Integration\Application\LoadedLifecycleState::$loadScopeDisposed = true;
    });

    return \Phalanx\Console\Command\CommandGroup::of([
        'loaded' => \Phalanx\Console\Tests\Integration\Application\LoadedLifecycleCommand::class,
    ]);
};
PHP);

        return $dir;
    }
}

final class LifecycleCommand implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(CommandContext $ctx): int
    {
        $ctx->onDispose(static function (): void {
            self::$disposed = true;
        });

        return 0;
    }
}

final class ThrowingLifecycleCommand implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(CommandContext $ctx): int
    {
        $ctx->onDispose(static function (): void {
            self::$disposed = true;
        });

        throw new RuntimeException('expected failure');
    }
}

final class LoadedLifecycleCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $ctx->onDispose(static function (): void {
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

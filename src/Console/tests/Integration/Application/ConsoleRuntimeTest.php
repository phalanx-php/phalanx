<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Application;
use Phalanx\Console\Application\Console;
use Phalanx\Console\Application\ConsoleRuntimeRunner;
use Phalanx\Console\Command\Runtime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleRuntimeTest extends TestCase
{
    #[Test]
    public function returnsConsoleRunnerForConsoleApplication(): void
    {
        $runtime = new Runtime(['error_handler' => false]);
        $app = Console::starting()->build();

        self::assertInstanceOf(ConsoleRuntimeRunner::class, $runtime->getRunner($app));
    }

    #[Test]
    public function rejectsBareRuntimeApplication(): void
    {
        $runtime = new Runtime(['error_handler' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Console runtime expects an ConsoleApplication');

        $runtime->getRunner(Application::starting()->compile());
    }

    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }
    }
}

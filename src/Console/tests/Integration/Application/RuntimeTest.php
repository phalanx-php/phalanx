<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Application;

use Phalanx\Console\Application\RuntimeRunner;
use Phalanx\Console\Command\Runtime;
use Phalanx\Console\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RuntimeTest extends TestCase
{
    #[Test]
    public function returnsRunnerForApplication(): void
    {
        $runtime = new Runtime(['error_handler' => false]);
        $app = self::console()->build();

        self::assertInstanceOf(RuntimeRunner::class, $runtime->getRunner($app));
    }

    #[Test]
    public function rejectsBareRuntimeApplication(): void
    {
        $runtime = new Runtime(['error_handler' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Console runtime expects an Application');

        $runtime->getRunner($this->application());
    }

    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }
    }
}

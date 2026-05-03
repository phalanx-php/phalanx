<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration;

use Phalanx\Application;
use Phalanx\Archon\Archon;
use Phalanx\Archon\ArchonRuntimeRunner;
use Phalanx\Archon\Runtime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArchonRuntimeTest extends TestCase
{
    #[Test]
    public function returnsArchonRunnerForArchonApplication(): void
    {
        $runtime = new Runtime();
        $app = Archon::starting()->build();

        self::assertInstanceOf(ArchonRuntimeRunner::class, $runtime->getRunner($app));
    }

    #[Test]
    public function rejectsBareAegisApplication(): void
    {
        $runtime = new Runtime();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archon runtime expects an ArchonApplication');

        $runtime->getRunner(Application::starting()->compile());
    }

    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }
    }
}

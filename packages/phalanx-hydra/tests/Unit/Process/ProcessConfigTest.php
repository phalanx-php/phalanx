<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Unit\Process;

use Phalanx\Hydra\Process\ProcessConfig;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessConfigTest extends TestCase
{
    #[Test]
    public function workerCommandStartsWithPhpBinary(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        self::assertSame(PHP_BINARY, $cmd[0]);
    }

    #[Test]
    public function workerCommandIncludesScriptAndAutoload(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        self::assertContains('/tmp/worker', $cmd);
        self::assertContains('--autoload=/tmp/autoload.php', $cmd);
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function workerCommandForwardsOpenswooleWhenLoaded(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        // A `-d` flag followed by `extension=...openswoole.so` proves the
        // extension is forwarded to the child process invocation.
        $found = false;
        $count = count($cmd);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($cmd[$i] === '-d' && str_contains($cmd[$i + 1], 'openswoole')) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'workerCommand() did not include a -d extension=...openswoole flag pair');
    }
}

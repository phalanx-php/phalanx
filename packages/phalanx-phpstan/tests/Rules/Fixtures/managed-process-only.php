<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use OpenSwoole\Core\Process\Manager;
use OpenSwoole\Process as OpenSwooleProcess;
use OpenSwoole\Process\Pool as OpenSwooleProcessPool;
use Phalanx\System\StreamingProcess;
use Symfony\Component\Process\Process;

final class ManagedProcessOnlyFixture
{
    public function openRawProcess(): void
    {
        $process = proc_open(['php', '-v'], [], $pipes);
        proc_get_status($process);
        proc_terminate($process);
        proc_close($process);

        new Process(['php', '-v']);
        Process::fromShellCommandline('php -v');
        new OpenSwooleProcess(static function (): void {
        });
        new OpenSwooleProcessPool(1);
        new \Swoole\Process(static function (): void {
        });
        new \Swoole\Process\Pool(1);
        new Manager();
        StreamingProcess::from('php', '-v');
    }
}

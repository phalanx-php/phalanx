<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Symfony\Component\Process\Process;

final class ManagedProcessOnlyInternalFixture
{
    public function openRawProcess(): void
    {
        $process = proc_open(['php', '-v'], [], $pipes);
        proc_get_status($process);
        proc_terminate($process);
        proc_close($process);

        new Process(['php', '-v']);
    }
}

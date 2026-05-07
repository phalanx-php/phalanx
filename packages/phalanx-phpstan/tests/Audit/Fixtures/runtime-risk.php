<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Audit\Fixtures;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use React\EventLoop\Loop;
use Symfony\Component\Process\Process;

final class RuntimeRiskFixture
{
    public function __invoke(): void
    {
        Runtime::enableCoroutine(true, Runtime::HOOK_TCP);
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_STDIO]);
        Coroutine::create(static function (): void {
        });

        proc_open(['php', '-v'], [], $pipes);
        fread(STDIN, 1);

        new Channel();
        new Process(['php', '-v']);
        Loop::get();
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Audit;

use Phalanx\PHPStan\Audit\RuntimeRisk;
use Phalanx\PHPStan\Audit\RuntimeRiskScanner;
use PHPUnit\Framework\TestCase;

final class RuntimeRiskScannerTest extends TestCase
{
    public function testScansRuntimeRiskSymbolsWithoutFailingThePhpstanGate(): void
    {
        $risks = (new RuntimeRiskScanner())->scanFile(__DIR__ . '/Fixtures/runtime-risk.php');
        $symbols = array_map(
            static fn(RuntimeRisk $risk): string => $risk->category . ':' . $risk->symbol,
            $risks,
        );

        self::assertContains('runtime_hooks:Runtime::enableCoroutine', $symbols);
        self::assertContains('runtime_hooks:Runtime::HOOK_TCP', $symbols);
        self::assertContains('runtime_hooks:Coroutine::set', $symbols);
        self::assertContains('runtime_hooks:SWOOLE_HOOK_STDIO', $symbols);
        self::assertContains('raw_coroutine_spawn:Coroutine::create', $symbols);
        self::assertContains('process:proc_open()', $symbols);
        self::assertContains('raw_stream_io:fread()', $symbols);
        self::assertContains('raw_channel:new Channel', $symbols);
        self::assertContains('process:new Process', $symbols);
        self::assertContains('stale_async_dependency:React\EventLoop\Loop', $symbols);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Audit;

use Phalanx\PHPStan\Audit\RuntimeRisk;
use Phalanx\PHPStan\Audit\RuntimeRiskScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeRiskScannerTest extends TestCase
{
    #[Test]
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
        self::assertContains('raw_channel:new Swoole\Coroutine\Channel', $symbols);
        self::assertContains('process:new Symfony\Component\Process\Process', $symbols);
        self::assertContains('process:Symfony\Component\Process\Process::fromShellCommandline', $symbols);
        self::assertContains('process:new Swoole\Process', $symbols);
        self::assertContains('process:new Swoole\Process\Pool', $symbols);
    }

    #[Test]
    public function testDoesNotReportSameNamespaceChannelWrapperAsRawSwooleChannel(): void
    {
        $file = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true) . '.php';

        try {
            file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

namespace Phalanx\Stream;

final class LocalChannelFixture
{
    public function __invoke(): void
    {
        new Channel();
    }
}
PHP);

            $risks = (new RuntimeRiskScanner())->scanFile($file);

            self::assertSame([], $risks);
        } finally {
            unlink($file);
        }
    }
}

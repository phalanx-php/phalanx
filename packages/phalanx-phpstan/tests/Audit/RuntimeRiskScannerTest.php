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
        self::assertContains('raw_channel:new OpenSwoole\Coroutine\Channel', $symbols);
        self::assertContains('process:new Symfony\Component\Process\Process', $symbols);
        self::assertContains('process:Symfony\Component\Process\Process::fromShellCommandline', $symbols);
        self::assertContains('process:new OpenSwoole\Process', $symbols);
        self::assertContains('process:new OpenSwoole\Process\Pool', $symbols);
        self::assertContains('process:new OpenSwoole\Core\Process\Manager', $symbols);
        self::assertContains('stale_async_dependency:React\EventLoop\Loop', $symbols);
        self::assertContains('stale_async_dependency:Amp\Future', $symbols);
        self::assertContains('stale_async_dependency:Revolt\EventLoop', $symbols);
    }

    public function testDoesNotReportSameNamespaceChannelWrapperAsRawOpenSwooleChannel(): void
    {
        $file = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true) . '.php';

        try {
            file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

namespace Phalanx\Styx;

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

    public function testSkipsNestedVendorTrees(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true);
        $vendor = $root . '/packages/example/vendor/react/event-loop';
        mkdir($vendor, recursive: true);

        try {
            file_put_contents($vendor . '/Loop.php', <<<'PHP'
<?php

namespace Example;

use React\EventLoop\Loop;
PHP);

            $risks = (new RuntimeRiskScanner())->scanPaths([$root]);

            self::assertSame([], $risks);
        } finally {
            @unlink($vendor . '/Loop.php');
            @rmdir($vendor);
            @rmdir(dirname($vendor));
            @rmdir(dirname($vendor, 2));
            @rmdir(dirname($vendor, 3));
            @rmdir(dirname($vendor, 4));
            @rmdir($root);
        }
    }

    public function testScansComposerManifestsForStaleAsyncPackages(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true);
        mkdir($root);

        try {
            file_put_contents($root . '/composer.json', <<<'JSON'
{
    "require": {
        "php": "^8.4",
        "react/event-loop": "^1.5"
    },
    "require-dev": {
        "amphp/amp": "^3.0"
    }
}
JSON);

            file_put_contents($root . '/composer.lock', <<<'JSON'
{
    "packages": [
        {"name": "revolt/event-loop"}
    ],
    "packages-dev": [
        {"name": "clue/stream-filter"}
    ]
}
JSON);

            $risks = (new RuntimeRiskScanner())->scanPaths([$root]);
            $symbols = array_map(
                static fn(RuntimeRisk $risk): string => $risk->category . ':' . $risk->symbol,
                $risks,
            );

            self::assertContains('stale_async_dependency:composer package react/event-loop', $symbols);
            self::assertContains('stale_async_dependency:composer package amphp/amp', $symbols);
            self::assertContains('stale_async_dependency:composer package revolt/event-loop', $symbols);
            self::assertContains('stale_async_dependency:composer package clue/stream-filter', $symbols);
        } finally {
            @unlink($root . '/composer.lock');
            @unlink($root . '/composer.json');
            @rmdir($root);
        }
    }

    public function testScansComposerManifestWhenPassedAsAFilePath(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true);
        mkdir($root);
        $file = $root . '/composer.json';

        try {
            file_put_contents($file, <<<'JSON'
{
    "require": {
        "react/event-loop": "^1.5"
    }
}
JSON);

            $risks = (new RuntimeRiskScanner())->scanPaths([$file]);
            $symbols = array_map(
                static fn(RuntimeRisk $risk): string => $risk->category . ':' . $risk->symbol,
                $risks,
            );

            self::assertContains('stale_async_dependency:composer package react/event-loop', $symbols);
        } finally {
            @unlink($file);
            @rmdir($root);
        }
    }

    public function testScansGroupedUseStaleAsyncImports(): void
    {
        $file = sys_get_temp_dir() . '/' . uniqid('phalanx-risk-', true) . '.php';

        try {
            file_put_contents($file, <<<'PHP_WRAP'
            <?php
            declare(strict_types=1);
            namespace Phalanx\PHPStan\Tests\Audit\Fixtures;
            use Amp\{Future};
            use React\Promise\{Deferred as ReactDeferred};
            use Revolt\{EventLoop};
            PHP_WRAP);

            $risks = (new RuntimeRiskScanner())->scanFile($file);
            $symbols = array_map(
                static fn(RuntimeRisk $risk): string => $risk->category . ':' . $risk->symbol,
                $risks,
            );

            self::assertContains('stale_async_dependency:Amp\Future', $symbols);
            self::assertContains('stale_async_dependency:React\Promise\Deferred', $symbols);
            self::assertContains('stale_async_dependency:Revolt\EventLoop', $symbols);
            self::assertNotContains('stale_async_dependency:React\Promise', $symbols);
            self::assertNotContains('stale_async_dependency:ReactDeferred', $symbols);
        } finally {
            @unlink($file);
        }
    }
}

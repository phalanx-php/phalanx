<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Testing;

use Phalanx\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Testing\Probe\DisposalProbe;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScopedTestAppLifecycleTest extends TestCase
{
    #[Test]
    public function run_boots_scope_and_disposes(): void
    {
        $probe = new DisposalProbe();

        TestScope::run(
            static function (ExecutionScope $scope) use ($probe): void {
                $scope->onDispose($probe->track('scope'));
                Assert::assertInstanceOf(ExecutionScope::class, $scope);
            },
        );

        $probe->assertDisposed('scope');
    }

    #[Test]
    public function compile_reuses_app_across_runs(): void
    {
        $instances = [];

        $app = TestScope::compile(
            services: static function (Services $s, array $ctx): void {
                $s->singleton(SharedCounter::class)
                    ->factory(static fn() => new SharedCounter());
            },
        );

        $app->run(static function (ExecutionScope $scope) use (&$instances): void {
            $counter = $scope->service(SharedCounter::class);
            $counter->value++;
            $instances[] = spl_object_id($counter);
        });

        $app->run(static function (ExecutionScope $scope) use (&$instances): void {
            $counter = $scope->service(SharedCounter::class);
            Assert::assertSame(1, $counter->value);
            $instances[] = spl_object_id($counter);
        });

        $app->shutdown();

        Assert::assertSame($instances[0], $instances[1]);
    }

    #[Test]
    public function run_propagates_test_exceptions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test failure');

        TestScope::run(static function (ExecutionScope $scope): void {
            throw new RuntimeException('test failure');
        });
    }

    #[Test]
    public function context_flows_to_services(): void
    {
        TestScope::run(
            static function (ExecutionScope $scope): void {
                $config = $scope->service(TestConfig::class);
                Assert::assertSame('test_db', $config->dbName);
            },
            services: static function (Services $s, array $ctx): void {
                $s->config(TestConfig::class, static fn(array $c) => new TestConfig($c['DB_NAME'] ?? 'default'));
            },
            context: ['DB_NAME' => 'test_db'],
        );
    }

    #[Test]
    public function scope_disposes_even_when_test_throws(): void
    {
        $probe = new DisposalProbe();

        try {
            TestScope::run(static function (ExecutionScope $scope) use ($probe): void {
                $scope->onDispose($probe->track('cleanup'));
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $probe->assertDisposed('cleanup');
    }
}

final class SharedCounter
{
    public int $value = 0;
}

final class TestConfig
{
    public function __construct(
        public readonly string $dbName,
    ) {}
}

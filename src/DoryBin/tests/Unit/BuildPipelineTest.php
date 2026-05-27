<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use Phalanx\Cancellation\Cancelled;
use Phalanx\DoryBin\BuildProfile;
use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\DoryBin\Pipeline\BuildPipeline;
use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildPipeline::class)]
final class BuildPipelineTest extends TestCase
{
    #[Test]
    public function stages_execute_in_order(): void
    {
        $order = [];
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('preflight', static function () use (&$order): StageResult {
            $order[] = 'preflight';
            return new StageResult('preflight', true, false, 1.0, 'ok');
        }));

        $pipeline->add(self::stage('build', static function () use (&$order): StageResult {
            $order[] = 'build';
            return new StageResult('build', true, false, 2.0, 'ok');
        }));

        $pipeline->add(self::stage('verify', static function () use (&$order): StageResult {
            $order[] = 'verify';
            return new StageResult('verify', true, false, 3.0, 'ok');
        }));

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertSame(['preflight', 'build', 'verify'], $order);
        self::assertCount(3, $results);
    }

    #[Test]
    public function skippable_stage_produces_skipped_result(): void
    {
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('sparta', static function (): StageResult {
            return new StageResult('sparta', true, false, 1.0, 'ok');
        }));

        $pipeline->add(self::stage('marathon', static function (): StageResult {
            self::fail('Skippable stage should not execute');
        }, canSkip: true));

        $pipeline->add(self::stage('thermopylae', static function (): StageResult {
            return new StageResult('thermopylae', true, false, 1.0, 'ok');
        }));

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertCount(3, $results);
        self::assertTrue($results[1]->skipped);
        self::assertSame('marathon', $results[1]->stageName);
    }

    #[Test]
    public function failure_stops_pipeline(): void
    {
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('zeus', static function (): StageResult {
            return new StageResult('zeus', false, false, 1.0, 'Thunder failed');
        }));

        $pipeline->add(self::stage('apollo', static function (): StageResult {
            self::fail('Should not execute after failure');
        }));

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertSame('zeus', $results[0]->stageName);
    }

    #[Test]
    public function exception_produces_failure_result_and_stops(): void
    {
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('poseidon', static function (): StageResult {
            throw new \RuntimeException('The seas rage');
        }));

        $pipeline->add(self::stage('hades', static function (): StageResult {
            self::fail('Should not execute after exception');
        }));

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertStringContainsString('The seas rage', $results[0]->summary);
    }

    #[Test]
    public function empty_pipeline_returns_empty_results(): void
    {
        $pipeline = new BuildPipeline();

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertSame([], $results);
    }

    #[Test]
    public function results_collect_all_stage_results(): void
    {
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('ares', static function (): StageResult {
            return new StageResult('ares', true, false, 10.5, 'War prepared');
        }));

        $pipeline->add(self::stage('hephaestus', static function (): StageResult {
            return new StageResult('hephaestus', true, false, 25.3, 'Forge heated');
        }));

        $results = $pipeline->execute($this->stubScope(), self::stubContext());

        self::assertCount(2, $results);
        self::assertSame('ares', $results[0]->stageName);
        self::assertSame(10.5, $results[0]->durationMs);
        self::assertSame('hephaestus', $results[1]->stageName);
        self::assertSame(25.3, $results[1]->durationMs);
    }

    #[Test]
    public function cancelled_exception_propagates_without_being_caught(): void
    {
        $pipeline = new BuildPipeline();

        $pipeline->add(self::stage('demeter', static function (): StageResult {
            throw new Cancelled('Scope cancelled');
        }));

        $pipeline->add(self::stage('dionysus', static function (): StageResult {
            self::fail('Should not execute after cancellation');
        }));

        $this->expectException(Cancelled::class);
        $this->expectExceptionMessage('Scope cancelled');

        $pipeline->execute($this->stubScope(), self::stubContext());
    }

    /**
     * @return TaskScope&TaskExecutor
     */
    private function stubScope(): TaskScope&TaskExecutor
    {
        return $this->createStub(BuildPipelineScopeStub::class);
    }

    private static function stubContext(): SpcBuildContext
    {
        $profile = new BuildProfileDefinition(
            profile: BuildProfile::Mini,
            description: 'Test profile',
            phpVersion: '8.4',
            iniSettings: [],
            iniPath: '/tmp',
            iniScanDir: '/tmp',
            requiredExtensions: [],
            optionalExtensions: [],
            openSwooleVersion: '26.2.0',
            openSwooleFeatures: [],
            phalanxPackages: [],
            spcRegistries: [],
        );

        return new SpcBuildContext(
            spcBinaryPath: '/usr/local/bin/spc',
            buildRoot: '/tmp/dory-build',
            registryPath: '/tmp/dory-build/registry',
            sourcePath: '/tmp/dory-build/source',
            outputPath: '/tmp/dory-build/bin/dory',
            environment: [],
            profile: $profile,
            os: 'Darwin',
            arch: 'arm64',
            workspaceRoot: '/tmp',
        );
    }

    /**
     * @param \Closure(): StageResult $invoke
     */
    private static function stage(string $name, \Closure $invoke, bool $canSkip = false): BuildStage
    {
        return new class($name, $invoke, $canSkip) implements BuildStage {
            public string $description = '';

            public function __construct(
                public string $name,
                private \Closure $invoke,
                private bool $skip,
            ) {
                $this->description = "Test stage: {$name}";
            }

            public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
            {
                return ($this->invoke)();
            }

            public function canSkip(SpcBuildContext $context): bool
            {
                return $this->skip;
            }
        };
    }
}

/**
 * @internal Stub interface that combines TaskScope and TaskExecutor for testing.
 * PHPUnit can mock interfaces but not final classes or intersection types directly.
 */
interface BuildPipelineScopeStub extends TaskScope, TaskExecutor
{
}

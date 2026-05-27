<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use Phalanx\DoryBin\BuildOutcome;
use Phalanx\DoryBin\Pipeline\StageResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildOutcome::class)]
final class BuildOutcomeTest extends TestCase
{
    #[Test]
    public function dry_run_factory_returns_successful_empty_outcome(): void
    {
        $outcome = BuildOutcome::dryRun();

        self::assertTrue($outcome->success);
        self::assertSame([], $outcome->stages);
        self::assertNull($outcome->binaryPath);
        self::assertNull($outcome->manifest);
        self::assertSame(0.0, $outcome->totalMs);
    }

    #[Test]
    public function complete_is_true_when_all_stages_succeed(): void
    {
        $outcome = new BuildOutcome(
            success: true,
            stages: [
                new StageResult('preflight', true, false, 12.0, 'OK'),
                new StageResult('download', true, false, 450.0, 'OK'),
            ],
            binaryPath: '/tmp/zeus-build/bin/dory',
            manifest: null,
            totalMs: 462.0,
        );

        self::assertTrue($outcome->complete);
    }

    #[Test]
    public function complete_is_true_when_stages_are_skipped(): void
    {
        $outcome = new BuildOutcome(
            success: true,
            stages: [
                new StageResult('preflight', true, false, 10.0, 'OK'),
                new StageResult('download', true, true, 0.0, 'Skipped (cached)'),
            ],
            binaryPath: '/tmp/zeus-build/bin/dory',
            manifest: null,
            totalMs: 10.0,
        );

        self::assertTrue($outcome->complete);
    }

    #[Test]
    public function complete_is_true_when_no_stages(): void
    {
        // array_all on empty array is vacuously true
        $outcome = BuildOutcome::dryRun();

        self::assertTrue($outcome->complete);
    }

    #[Test]
    public function complete_is_false_when_a_stage_fails(): void
    {
        $outcome = new BuildOutcome(
            success: false,
            stages: [
                new StageResult('preflight', true, false, 10.0, 'OK'),
                new StageResult('build-php', false, false, 30.0, 'compiler error'),
            ],
            binaryPath: null,
            manifest: null,
            totalMs: 40.0,
        );

        self::assertFalse($outcome->complete);
    }

    #[Test]
    public function failed_stage_returns_name_of_first_failing_stage(): void
    {
        $outcome = new BuildOutcome(
            success: false,
            stages: [
                new StageResult('preflight', true, false, 10.0, 'OK'),
                new StageResult('build-libraries', false, false, 120.0, 'library compile failed'),
                new StageResult('build-php', false, false, 0.0, 'aborted'),
            ],
            binaryPath: null,
            manifest: null,
            totalMs: 130.0,
        );

        self::assertSame('build-libraries', $outcome->failedStage);
    }

    #[Test]
    public function failed_stage_returns_empty_string_when_all_succeed(): void
    {
        $outcome = new BuildOutcome(
            success: true,
            stages: [
                new StageResult('preflight', true, false, 10.0, 'OK'),
                new StageResult('build-php', true, false, 200.0, 'OK'),
            ],
            binaryPath: '/tmp/dory',
            manifest: null,
            totalMs: 210.0,
        );

        self::assertSame('', $outcome->failedStage);
    }

    #[Test]
    public function failed_stage_ignores_skipped_stages(): void
    {
        $outcome = new BuildOutcome(
            success: false,
            stages: [
                new StageResult('preflight', true, false, 5.0, 'OK'),
                new StageResult('download', false, true, 0.0, 'Skipped (cached)'),
                new StageResult('build-php', false, false, 50.0, 'fatal error'),
            ],
            binaryPath: null,
            manifest: null,
            totalMs: 55.0,
        );

        // Skipped stages are not failures; only the non-skipped false stage counts
        self::assertSame('build-php', $outcome->failedStage);
    }
}

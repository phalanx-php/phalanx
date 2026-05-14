<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Lenses;

use Phalanx\Diagnostics\DoctorReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class RuntimeLensTest extends TestCase
{
    public function testLensIsAvailable(): void
    {
        $app = TestApp::boot();

        try {
            self::assertInstanceOf(RuntimeLens::class, $app->runtime);
        } finally {
            $app->shutdown();
        }
    }

    public function testReportProducesDoctorReport(): void
    {
        $app = TestApp::boot();

        try {
            $report = $app->runtime->report();

            self::assertInstanceOf(DoctorReport::class, $report);
            self::assertNotEmpty($report->checks);
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertHealthyPassesInsideScopedRun(): void
    {
        $app = TestApp::boot();

        try {
            $reportedHealthy = false;

            $app->application->scoped(Task::named(
                'demo.runtime.health',
                static function (ExecutionScope $_scope) use ($app, &$reportedHealthy): void {
                    $app->runtime->assertHealthy();
                    $reportedHealthy = true;
                },
            ));

            self::assertTrue($reportedHealthy, 'assertHealthy() returned without throwing inside the scoped run');
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertResourcesCleanPassesOnIdleApp(): void
    {
        $app = TestApp::boot();

        try {
            $app->runtime->assertResourcesClean();
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertCheckFailsErrorsWhenCheckIsUnknown(): void
    {
        $app = TestApp::boot();

        try {
            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage("Runtime check 'no.such.check' was not found");

            $app->runtime->assertCheckFails('no.such.check');
        } finally {
            $app->shutdown();
        }
    }
}

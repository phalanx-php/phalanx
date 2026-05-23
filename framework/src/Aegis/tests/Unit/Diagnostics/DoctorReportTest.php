<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\DoctorCheck;
use Phalanx\Diagnostics\DoctorReport;
use Phalanx\Diagnostics\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctorReportTest extends TestCase
{
    public function testIsHealthyWhenAllChecksPass(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.a', true),
            new DoctorCheck('probe.b', true),
        ]);

        self::assertTrue($report->isHealthy());
    }

    public function testIsUnhealthyWhenRequiredCheckFails(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.required', false),
        ]);

        self::assertFalse($report->isHealthy());
    }

    public function testIsHealthyWhenOnlyOptionalCheckFails(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.optional', false, '', Severity::Optional),
        ]);

        self::assertTrue($report->isHealthy());
    }

    public function testIsHealthyWhenOnlyInformationalCheckFails(): void
    {
        // Informational checks cannot have ok=false in practice, but the
        // health gate must never read their ok value regardless.
        $report = new DoctorReport([
            new DoctorCheck('probe.info', false, '', Severity::Informational),
        ]);

        self::assertTrue($report->isHealthy());
    }

    public function testMixedRequiredPassOptionalFail(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.required', true),
            new DoctorCheck('probe.optional', false, '', Severity::Optional),
        ]);

        self::assertTrue($report->isHealthy());
    }

    public function testMixedRequiredFailOptionalPass(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.required', false),
            new DoctorCheck('probe.optional', true, '', Severity::Optional),
        ]);

        self::assertFalse($report->isHealthy());
    }

    public function testIsHealthyWithNoChecks(): void
    {
        // No Required checks → nothing to fail.
        $report = new DoctorReport([]);

        self::assertTrue($report->isHealthy());
    }

    #[Test]
    public function isUnhealthyWhenMultipleRequiredChecksFail(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.a', false),
            new DoctorCheck('probe.b', false),
        ]);

        self::assertFalse($report->isHealthy());
    }

    public function testIsHealthyWhenAllChecksAreOptional(): void
    {
        $report = new DoctorReport([
            new DoctorCheck('probe.a', false, '', Severity::Optional),
            new DoctorCheck('probe.b', false, '', Severity::Optional),
        ]);

        self::assertTrue($report->isHealthy());
    }
}

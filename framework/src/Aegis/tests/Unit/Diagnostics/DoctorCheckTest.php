<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\DoctorCheck;
use Phalanx\Diagnostics\Severity;
use PHPUnit\Framework\TestCase;

final class DoctorCheckTest extends TestCase
{
    public function testSeverityDefaultsToRequired(): void
    {
        $check = new DoctorCheck('probe.name', true, 'detail');

        self::assertSame(Severity::Required, $check->severity);
    }

    public function testExplicitOptionalSeverity(): void
    {
        $check = new DoctorCheck('probe.optional', false, 'detail', Severity::Optional);

        self::assertSame(Severity::Optional, $check->severity);
        self::assertFalse($check->ok);
    }

    public function testExplicitInformationalSeverity(): void
    {
        $check = new DoctorCheck('probe.info', true, 'detail', Severity::Informational);

        self::assertSame(Severity::Informational, $check->severity);
    }

    public function testToArrayIncludesSeverityValue(): void
    {
        $check = new DoctorCheck('probe.name', true, 'detail', Severity::Optional);

        self::assertSame('optional', $check->toArray()['severity']);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Doctor;

use Phalanx\Cli\Doctor\CheckStatus;
use Phalanx\Cli\Doctor\ExtensionCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionCheckTest extends TestCase
{
    #[Test]
    public function passesForLoadedExtension(): void
    {
        $check = (new ExtensionCheck('json'))();

        self::assertSame('ext-json', $check->name);
        self::assertSame(CheckStatus::Pass, $check->status);
    }

    #[Test]
    public function warnsForMissingOptionalExtension(): void
    {
        $check = (new ExtensionCheck('nonexistent_ext_xyz'))();

        self::assertSame('ext-nonexistent_ext_xyz', $check->name);
        self::assertSame(CheckStatus::Warn, $check->status);
        self::assertStringContainsString('optional', $check->message);
    }

    #[Test]
    public function failsForMissingRequiredExtension(): void
    {
        $check = (new ExtensionCheck('nonexistent_ext_xyz', required: true))();

        self::assertSame(CheckStatus::Fail, $check->status);
        self::assertNotNull($check->remediation);
    }

    #[Test]
    public function showsVersionWhenAvailable(): void
    {
        $check = (new ExtensionCheck('json'))();

        self::assertNotSame('loaded', $check->message);
    }
}

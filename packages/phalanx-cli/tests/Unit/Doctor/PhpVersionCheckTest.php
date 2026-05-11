<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Doctor;

use Phalanx\Cli\Doctor\CheckStatus;
use Phalanx\Cli\Doctor\PhpVersionCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    #[Test]
    public function passesOnPhp84OrHigher(): void
    {
        $check = (new PhpVersionCheck())();

        self::assertSame('PHP Version', $check->name);
        self::assertSame(CheckStatus::Pass, $check->status);
        self::assertStringContainsString(PHP_VERSION, $check->message);
    }
}

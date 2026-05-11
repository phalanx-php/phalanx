<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Integration\Command;

use Phalanx\Cli\Command\DoctorCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctorCommandTest extends TestCase
{
    #[Test]
    public function executesAndShowsChecks(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('Phalanx Environment Check', $output);
        self::assertStringContainsString('PHP Version', $output);
    }

    #[Test]
    public function phpVersionAlwaysPasses(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString(PHP_VERSION, $output);
    }

    #[Test]
    public function showsExtensionChecks(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('ext-pcntl', $output);
        self::assertStringContainsString('ext-sockets', $output);
    }
}

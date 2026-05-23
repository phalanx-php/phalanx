<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Integration\Command;

use Phalanx\Cli\Command\DoctorCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
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
        self::assertStringContainsString('OpenSwoole', $output);
    }

    #[Test]
    public function phpVersionPassesWithCorrectIcon(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertMatchesRegularExpression('/✓.*PHP Version.*PHP ' . preg_quote(PHP_VERSION, '/') . '/', $output);
    }

    #[Test]
    public function showsOptionalExtensionChecks(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('ext-openssl', $output);
        self::assertStringContainsString('ext-curl', $output);
    }

    #[Test]
    public function returnsSuccessWhenNoFailures(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        if (extension_loaded('openswoole')) {
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        } else {
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
        }
    }
}

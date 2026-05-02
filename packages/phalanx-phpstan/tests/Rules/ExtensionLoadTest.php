<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use PHPUnit\Framework\TestCase;

final class ExtensionLoadTest extends TestCase
{
    public function testExtensionNeonLoads(): void
    {
        $command = implode(' ', [
            escapeshellarg(__DIR__ . '/../../../../vendor/bin/phpstan'),
            'analyse',
            '--configuration=' . escapeshellarg(__DIR__ . '/Fixtures/extension-load.neon'),
            '--error-format=raw',
            '--no-progress',
            escapeshellarg(__DIR__ . '/Fixtures/extension-load.php'),
        ]);

        exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}

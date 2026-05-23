<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use PHPUnit\Framework\TestCase;

final class ExtensionLoadTest extends TestCase
{
    public function testExtensionNeonLoads(): void
    {
        $phpstan = null;
        foreach ([dirname(__DIR__, 5), dirname(__DIR__, 2)] as $root) {
            $candidate = $root . '/vendor/bin/phpstan';
            if (is_file($candidate)) {
                $phpstan = $candidate;
                break;
            }
        }

        self::assertNotNull($phpstan, 'Could not locate vendor/bin/phpstan');

        $command = implode(' ', [
            escapeshellarg($phpstan),
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

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionLoadTest extends TestCase
{
    #[Test]
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

        self::assertSame(1, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString(
            'Use Phalanx\Scope\ExecutionScope instead of stale root-level Phalanx\ExecutionScope.',
            implode(PHP_EOL, $output),
        );
    }
}

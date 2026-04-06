<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class DevCleanupTest extends TestCase
{
    #[Test]
    public function no_commented_code_in_source(): void
    {
        $dirs = glob(__DIR__ . '/../../packages/*/src');
        self::assertNotEmpty($dirs, 'No package src/ directories found');

        $root = dirname(__DIR__, 2);
        $relativeDirs = array_map(
            static fn(string $d): string => str_replace($root . '/', '', $d),
            $dirs,
        );

        $command = implode(' ', [
            'php',
            'vendor/bin/swiss-knife',
            'check-commented-code',
            ...$relativeDirs,
        ]);

        $output = [];
        $exitCode = 0;
        exec("cd $root && $command 2>&1", $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "Commented-out code found:\n" . implode("\n", $output),
        );
    }
}

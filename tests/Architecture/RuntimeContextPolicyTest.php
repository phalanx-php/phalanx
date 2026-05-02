<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class RuntimeContextPolicyTest extends TestCase
{
    #[Test]
    public function packageSourceDoesNotReadProcessGlobals(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (glob($root . '/packages/*/src') ?: [] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::assertNotEmpty($files, 'No package source files found.');

        $offenders = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            foreach (['$_SERVER', '$_ENV', 'getenv(', 'putenv('] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = str_replace($root . '/', '', $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Phalanx package source must receive runtime state through explicit context:\n" . implode("\n", $offenders),
        );
    }
}

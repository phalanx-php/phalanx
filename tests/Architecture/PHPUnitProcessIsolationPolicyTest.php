<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class PHPUnitProcessIsolationPolicyTest extends TestCase
{
    #[Test]
    public function testsDoNotUsePhpunitProcessIsolation(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ([$root . '/tests', ...glob($root . '/packages/*/tests') ?: []] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::assertNotEmpty($files, 'No test files found.');

        $offenders = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }

            foreach (self::forbiddenTokens() as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = str_replace($root . '/', '', $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Phalanx tests must use runtime/app teardown instead of PHPUnit OS-process isolation:\n"
                . implode("\n", $offenders),
        );
    }

    /** @return list<string> */
    private static function forbiddenTokens(): array
    {
        return [
            'RunIn' . 'SeparateProcess',
            'RunTestsIn' . 'SeparateProcesses',
            'PreserveGlobal' . 'State',
        ];
    }
}

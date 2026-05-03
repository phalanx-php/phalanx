<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Meta-test: Archon has fully replaced its React/Promise era. Any new
 * `use React\…`, `PromiseInterface`, `Deferred`, or `Loop::` symbol in
 * Archon source or tests is a regression — those primitives belong to
 * the historical 0.1 substrate, not the OpenSwoole 0.2 runtime.
 */
final class NoReactImportsTest extends TestCase
{
    private const string PACKAGE_ROOT = __DIR__ . '/../..';

    /** @return list<string> */
    private static function archonPhpFiles(): array
    {
        $files = [];
        $roots = ['/src', '/tests'];

        foreach ($roots as $root) {
            $dir = self::PACKAGE_ROOT . $root;
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    #[Test]
    public function archonSourceContainsNoReactImports(): void
    {
        $offenders = [];
        $patterns  = [
            'use React\\\\',
            'PromiseInterface',
            'React\\\\Promise\\\\Deferred',
            'React\\\\EventLoop',
            '\\bLoop::',
        ];
        $regex = '/' . implode('|', $patterns) . '/';

        foreach (self::archonPhpFiles() as $path) {
            // Skip this meta-test file itself — it names the patterns it forbids.
            if (basename($path) === 'NoReactImportsTest.php') {
                continue;
            }

            $contents = file_get_contents($path);
            self::assertNotFalse($contents, "Failed to read {$path}");

            if (preg_match($regex, $contents) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "React-era imports detected in Archon — these belong to the historical 0.1 substrate:\n  - "
            . implode("\n  - ", $offenders),
        );
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Testing;

use Phalanx\Testing\TempWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TempWorkspaceTest extends TestCase
{
    #[Test]
    public function createsNestedFixtureFilesUnderOwnedRoot(): void
    {
        $workspace = TempWorkspace::create('phalanx-workspace-test-');

        try {
            $path = $workspace->file('nested/config.php', '<?php return true;');

            self::assertSame($workspace->path('nested/config.php'), $path);
            self::assertFileExists($path);
            self::assertSame('<?php return true;', $workspace->read('nested/config.php'));
        } finally {
            $root = $workspace->root;
            $workspace->cleanup();
        }

        self::assertDirectoryDoesNotExist($root);
    }

    #[Test]
    public function createsNestedDirectoriesAndMissingPathsWithoutLeaking(): void
    {
        $workspace = TempWorkspace::create('phalanx-workspace-test-');

        try {
            $dir = $workspace->dir('a/b/c');
            $missing = $workspace->missingPath('a/b/c/missing.txt');

            self::assertDirectoryExists($dir);
            self::assertFileDoesNotExist($missing);
        } finally {
            $root = $workspace->root;
            $workspace->cleanup();
        }

        self::assertDirectoryDoesNotExist($root);
    }

    #[Test]
    #[DataProvider('invalidWorkspacePaths')]
    public function rejectsPathsThatEscapeOwnedRoot(string $path): void
    {
        $workspace = TempWorkspace::create('phalanx-workspace-test-');

        try {
            try {
                $workspace->file($path, 'escape');
                self::fail("Expected {$path} to be rejected.");
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('Invalid temporary workspace path:', $exception->getMessage());
                self::assertDirectoryExists($workspace->root);
            }
        } finally {
            $root = $workspace->root;
            $workspace->cleanup();
        }

        self::assertDirectoryDoesNotExist($root);
    }

    #[Test]
    #[DataProvider('invalidWorkspacePrefixes')]
    public function rejectsPrefixesThatEscapeSystemTempRoot(string $prefix): void
    {
        try {
            TempWorkspace::create($prefix);
            self::fail("Expected {$prefix} to be rejected.");
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('Invalid temporary workspace prefix:', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWorkspacePaths(): iterable
    {
        yield 'parent' => ['../outside.php'];
        yield 'nested parent' => ['a/../../outside.php'];
        yield 'absolute' => ['/absolute.php'];
        yield 'windows drive' => ['C:/absolute.php'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWorkspacePrefixes(): iterable
    {
        yield 'empty' => [''];
        yield 'parent' => ['../escape-'];
        yield 'nested' => ['nested/path-'];
        yield 'windows drive' => ['C:/escape-'];
    }
}

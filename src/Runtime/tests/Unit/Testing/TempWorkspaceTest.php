<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Testing;

use Phalanx\Testing\TempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}

<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Support;

use Phalanx\Support\PackagePaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackagePathsTest extends TestCase
{
    #[Test]
    public function ancestorCandidatesWalkFromAnchorToParents(): void
    {
        $root = self::makeTempTree();

        try {
            $anchor = "{$root}/package/src/Response";
            mkdir($anchor, 0777, true);

            $candidates = PackagePaths::ancestorCandidates($anchor, 'resources/view.php', maxDepth: 3);

            self::assertSame(
                [
                    "{$root}/package/src/Response/resources/view.php",
                    "{$root}/package/src/resources/view.php",
                    "{$root}/package/resources/view.php",
                    "{$root}/resources/view.php",
                ],
                $candidates,
            );
        } finally {
            self::removeTree($root);
        }
    }

    #[Test]
    public function firstExistingFileReturnsNearestCandidate(): void
    {
        $root = self::makeTempTree();

        try {
            $nested = "{$root}/package/src/Response";
            $resource = "{$root}/package/resources/view.php";
            mkdir($nested, 0777, true);
            mkdir(dirname($resource), 0777, true);
            file_put_contents($resource, '<?php');

            $path = PackagePaths::firstExistingFile(
                PackagePaths::ancestorCandidates($nested, 'resources/view.php'),
            );

            self::assertSame($resource, $path);
        } finally {
            self::removeTree($root);
        }
    }

    #[Test]
    public function firstExistingDirectoryFindsStandaloneVendorFromSourceRoot(): void
    {
        $root = self::makeTempTree();

        try {
            $anchor = "{$root}/package/src";
            $vendor = "{$root}/package/vendor";
            mkdir($anchor, 0777, true);
            mkdir($vendor, 0777, true);

            $path = PackagePaths::firstExistingDirectory(
                PackagePaths::ancestorCandidates($anchor, 'vendor'),
            );

            self::assertSame($vendor, $path);
        } finally {
            self::removeTree($root);
        }
    }

    private static function makeTempTree(): string
    {
        $root = sys_get_temp_dir() . '/' . uniqid('phalanx-package-paths-', true);
        mkdir($root, 0777, true);

        return realpath($root) ?: $root;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $children = scandir($path);
        if ($children === false) {
            return;
        }

        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }

            $childPath = "{$path}/{$child}";
            if (is_dir($childPath)) {
                self::removeTree($childPath);
                continue;
            }

            unlink($childPath);
        }

        rmdir($path);
    }
}

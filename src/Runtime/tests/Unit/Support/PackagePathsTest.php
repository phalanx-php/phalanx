<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Support;

use Phalanx\Support\PackagePaths;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackagePathsTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function ancestorCandidatesWalkFromAnchorToParents(): void
    {
        $root = $this->tempWorkspace('phalanx-package-paths-')->path();

        $anchor = $this->tempWorkspace()->dir('package/src/Response');

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
    }

    #[Test]
    public function firstExistingFileReturnsNearestCandidate(): void
    {
        $root = $this->tempWorkspace('phalanx-package-paths-')->path();

        $nested = $this->tempWorkspace()->dir('package/src/Response');
        $resource = $this->tempWorkspace()->file('package/resources/view.php', '<?php');

        $path = PackagePaths::firstExistingFile(
            PackagePaths::ancestorCandidates($nested, 'resources/view.php'),
        );

        self::assertSame($resource, $path);
    }

    #[Test]
    public function firstExistingDirectoryFindsStandaloneVendorFromSourceRoot(): void
    {
        $root = $this->tempWorkspace('phalanx-package-paths-')->path();

        $anchor = $this->tempWorkspace()->dir('package/src');
        $vendor = $this->tempWorkspace()->dir('package/vendor');

        $path = PackagePaths::firstExistingDirectory(
            PackagePaths::ancestorCandidates($anchor, 'vendor'),
        );

        self::assertSame($vendor, $path);
    }
}

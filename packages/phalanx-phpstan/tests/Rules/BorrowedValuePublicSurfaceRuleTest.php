<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Pool\BorrowedValuePublicSurfaceRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BorrowedValuePublicSurfaceRule>
 */
final class BorrowedValuePublicSurfaceRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsBorrowedValuesOnPublicSurfaces(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-public-surface.php'],
            [
                [BorrowedValuePublicSurfaceRule::MESSAGE, 15],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 18],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 20],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 23],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 30],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 36],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 41],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 46],
                [BorrowedValuePublicSurfaceRule::MESSAGE, 52],
            ],
        );
    }

    public function testAllowsBorrowedValuesOnInternalSurfaces(): void
    {
        $file = __DIR__ . '/Fixtures/borrowed-value-public-surface.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new BorrowedValuePublicSurfaceRule($this->pathPolicy, self::createReflectionProvider());
    }
}

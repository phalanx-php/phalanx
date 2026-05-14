<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Pool\BorrowedValueBoundaryRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BorrowedValueBoundaryRule>
 */
final class BorrowedValueBoundaryRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsBorrowedValuesCrossingBoundaries(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-boundary.php'],
            [
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 30],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 31],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 32],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 39],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 44],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 51],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 57],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 65],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 70],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 75],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 80],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 91],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 96],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 105],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 110],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 111],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 118],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 123],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 134],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 139],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 140],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 141],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 146],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 147],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 156],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 163],
            ],
        );
    }

    public function testAllowsBorrowedValuesInsideInternalPoolBoundaries(): void
    {
        $file = __DIR__ . '/Fixtures/borrowed-value-internal-boundary.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    public function testClosureAliasTrackingIsScopedToTheDeclaringFunction(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-boundary-scope.php'],
            [
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 21],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 40],
            ],
        );
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new BorrowedValueBoundaryRule($this->pathPolicy);
    }
}

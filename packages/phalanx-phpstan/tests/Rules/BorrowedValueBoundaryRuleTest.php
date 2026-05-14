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
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 27],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 28],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 35],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 40],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 47],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 53],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 61],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 66],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 71],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 76],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 83],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 84],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 91],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 96],
            ],
        );
    }

    public function testAllowsBorrowedValuesInsideInternalPoolBoundaries(): void
    {
        $file = __DIR__ . '/Fixtures/borrowed-value-internal-boundary.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
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

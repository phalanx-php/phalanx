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
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 38],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 43],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 50],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 56],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 64],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 69],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 74],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 79],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 90],
                [BorrowedValueBoundaryRule::ARROW_RETURN_MESSAGE, 95],
                [BorrowedValueBoundaryRule::CHANNEL_MESSAGE, 104],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 109],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 110],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 117],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 122],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 133],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 138],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 139],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 140],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 145],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 146],
                [BorrowedValueBoundaryRule::RETURN_MESSAGE, 155],
                [BorrowedValueBoundaryRule::PROPERTY_MESSAGE, 162],
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

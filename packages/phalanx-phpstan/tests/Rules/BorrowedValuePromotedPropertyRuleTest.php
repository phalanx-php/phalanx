<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Pool\BorrowedValuePromotedPropertyRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BorrowedValuePromotedPropertyRule>
 */
final class BorrowedValuePromotedPropertyRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsBorrowedValuesStoredInPromotedProperties(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-boundary.php'],
            [
                [BorrowedValuePromotedPropertyRule::MESSAGE, 209],
            ],
        );
    }

    public function testAllowsPromotedBorrowedValuesInsideInternalPoolBoundaries(): void
    {
        $file = __DIR__ . '/Fixtures/borrowed-value-boundary.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new BorrowedValuePromotedPropertyRule($this->pathPolicy);
    }
}

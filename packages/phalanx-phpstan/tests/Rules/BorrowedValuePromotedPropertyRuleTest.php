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
    public function testReportsBorrowedValuesStoredInPromotedProperties(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-boundary.php'],
            [
                ['Borrowed values must not be stored in promoted properties; copy to an owned value first.', 94],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new BorrowedValuePromotedPropertyRule(new PathPolicy());
    }
}

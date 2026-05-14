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
                [BorrowedValuePromotedPropertyRule::MESSAGE, 208],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new BorrowedValuePromotedPropertyRule(new PathPolicy());
    }
}

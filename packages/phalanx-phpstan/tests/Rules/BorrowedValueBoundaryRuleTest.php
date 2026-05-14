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
    public function testReportsBorrowedValuesCrossingBoundaries(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/borrowed-value-boundary.php'],
            [
                ['Borrowed values must not be emitted through Styx channels; copy to an owned value before emit().', 25],
                ['Borrowed values must not be emitted through Styx channels; copy to an owned value before emit().', 26],
                ['Borrowed values must not be emitted through Styx channels; copy to an owned value before emit().', 33],
                ['Borrowed values must not be returned; copy to an owned value before leaving the borrow scope.', 38],
                ['Borrowed values must not be returned; copy to an owned value before leaving the borrow scope.', 44],
                ['Borrowed values must not be returned; copy to an owned value before leaving the borrow scope.', 52],
                ['Borrowed values must not be returned from arrow functions; copy to an owned value before leaving the borrow scope.', 57],
                ['Borrowed values must not be returned from arrow functions; copy to an owned value before leaving the borrow scope.', 62],
                ['Borrowed values must not be stored on object or static properties; copy to an owned value first.', 67],
                ['Borrowed values must not be stored on object or static properties; copy to an owned value first.', 68],
                ['Borrowed values must not be stored on object or static properties; copy to an owned value first.', 75],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new BorrowedValueBoundaryRule(new PathPolicy());
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Transaction\TransactionCallbackScopeRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TransactionCallbackScopeRule>
 */
final class TransactionCallbackScopeRuleTest extends RuleTestCase
{
    public function testReportsTransactionCallbacksThatRequestExecutionScope(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/transaction-callback-scope.php'],
            [
                ['Transaction callbacks must accept Phalanx\Scope\TransactionScope, not full ExecutionScope; transactions must not expose fan-out or worker dispatch.', 17],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new TransactionCallbackScopeRule(new PathPolicy());
    }
}

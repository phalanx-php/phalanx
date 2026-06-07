<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Runtime\NoGenericLifecycleRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoGenericLifecycleRule>
 */
final class NoGenericLifecycleRuleTest extends RuleTestCase
{
    public function testReportsGenericLifecycleReferences(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/generic-lifecycle.php'],
            [
                [
                    'Use concrete Phalanx lifecycle surfaces: module ::starting(), ServiceConfig hooks, scope onDispose(), and cancellation onCancel(); do not use generic lifecycle callback bags.',
                    11,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NoGenericLifecycleRule();
    }
}

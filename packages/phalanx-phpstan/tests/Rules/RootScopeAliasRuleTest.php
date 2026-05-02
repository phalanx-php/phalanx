<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Migration\RootScopeAliasRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RootScopeAliasRule>
 */
final class RootScopeAliasRuleTest extends RuleTestCase
{
    public function testReportsStaleRootScopeAliases(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/root-scope-alias.php'],
            [
                ['Use Phalanx\Scope\ExecutionScope instead of stale root-level Phalanx\ExecutionScope.', 7],
                ['Use Phalanx\Scope\Scope instead of stale root-level Phalanx\Scope.', 8],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RootScopeAliasRule(new PathPolicy());
    }
}

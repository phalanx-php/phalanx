<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\GenericScopeBagAccessRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<GenericScopeBagAccessRule>
 */
final class GenericScopeBagAccessRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsGenericScopeBagAccess(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/generic-scope-bag-access.php'],
            [
                [GenericScopeBagAccessRule::MESSAGE, 13],
                [GenericScopeBagAccessRule::MESSAGE, 14],
                [GenericScopeBagAccessRule::MESSAGE, 15],
            ],
        );
    }

    public function testAllowsInternalScopeBagAccess(): void
    {
        $file = __DIR__ . '/Fixtures/generic-scope-bag-access.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new GenericScopeBagAccessRule($this->pathPolicy);
    }
}

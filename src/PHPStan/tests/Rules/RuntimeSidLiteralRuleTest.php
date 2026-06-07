<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Runtime\RuntimeSidLiteralRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RuntimeSidLiteralRule>
 */
final class RuntimeSidLiteralRuleTest extends RuleTestCase
{
    public function testReportsBareRuntimeSidLiterals(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/runtime-sid-literals.php'],
            [
                [
                    'Runtime lifecycle resources, events, annotations, and counters use typed Sid enums; do not pass bare string lifecycle IDs in package code.',
                    11,
                ],
                [
                    'Runtime lifecycle resources, events, annotations, and counters use typed Sid enums; do not pass bare string lifecycle IDs in package code.',
                    12,
                ],
            ],
        );
    }

    public function testAllowsRuntimeInternals(): void
    {
        $fixture = __DIR__ . '/Fixtures/runtime-sid-literals-internal.php';

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new RuntimeSidLiteralRule(
            new PathPolicy(),
            [__DIR__ . '/Fixtures/runtime-sid-literals-internal.php'],
        );
    }
}

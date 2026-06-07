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
                    'Runtime resources use typed RuntimeResourceId enums; do not pass bare string resource IDs in package code.',
                    15,
                ],
                [
                    'Runtime annotations use typed RuntimeAnnotationId enums; do not pass bare string annotation IDs in package code.',
                    16,
                ],
                [
                    'Runtime resources use typed RuntimeResourceId enums; do not pass bare string resource IDs in package code.',
                    17,
                ],
                [
                    'Runtime events use typed RuntimeEventId enums; do not pass bare string event IDs in package code.',
                    18,
                ],
                [
                    'Runtime annotations use typed RuntimeAnnotationId enums; do not pass bare string annotation IDs in package code.',
                    19,
                ],
                [
                    'Runtime resources use typed RuntimeResourceId enums; do not pass bare string resource IDs in package code.',
                    20,
                ],
                [
                    'Runtime resources use typed RuntimeResourceId enums; do not pass bare string resource IDs in package code.',
                    21,
                ],
                [
                    'Runtime counters use typed RuntimeCounterId enums; do not pass bare string counter IDs in package code.',
                    22,
                ],
                [
                    'Runtime counters use typed RuntimeCounterId enums; do not pass bare string counter IDs in package code.',
                    23,
                ],
            ],
        );
    }

    public function testAllowsMatchingDomainMethodNamesOnNonRuntimeTypes(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/runtime-sid-literals-false-positives.php'], []);
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

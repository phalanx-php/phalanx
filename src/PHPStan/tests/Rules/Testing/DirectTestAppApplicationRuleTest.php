<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\DirectTestAppApplicationRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<DirectTestAppApplicationRule>
 */
final class DirectTestAppApplicationRuleTest extends RuleTestCase
{
    public function testFlagsDirectTestAppApplicationAccess(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/DirectTestAppApplicationViolation.php'],
            [
                [
                    'High-level Phalanx tests must not reach through TestApp->application; use TestApp::scoped(), '
                    . 'TestApp::start(), TestApp::runtime(), TestApp::supervisor(), or a package lens.',
                    16,
                ],
                [
                    'High-level Phalanx tests must not reach through TestApp->application; use TestApp::scoped(), '
                    . 'TestApp::start(), TestApp::runtime(), TestApp::supervisor(), or a package lens.',
                    21,
                ],
            ],
        );
    }

    public function testAcceptsSanctionedTestAppApis(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/DirectTestAppApplicationValid.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new DirectTestAppApplicationRule(new TestingPathPolicy());
    }
}

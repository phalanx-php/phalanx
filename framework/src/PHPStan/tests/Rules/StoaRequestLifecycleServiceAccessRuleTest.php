<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\StoaRequestLifecycleServiceAccessRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<StoaRequestLifecycleServiceAccessRule>
 */
final class StoaRequestLifecycleServiceAccessRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsStoaLifecycleServiceAccess(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/stoa-request-lifecycle-service-access.php'],
            [
                [StoaRequestLifecycleServiceAccessRule::MESSAGE, 17],
                [StoaRequestLifecycleServiceAccessRule::MESSAGE, 18],
                [StoaRequestLifecycleServiceAccessRule::MESSAGE, 19],
            ],
        );
    }

    public function testAllowsInternalStoaLifecycleServiceAccess(): void
    {
        $file = __DIR__ . '/Fixtures/stoa-request-lifecycle-service-access.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new StoaRequestLifecycleServiceAccessRule($this->pathPolicy);
    }
}

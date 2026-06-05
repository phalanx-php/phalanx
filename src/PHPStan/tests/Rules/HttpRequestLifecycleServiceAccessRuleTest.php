<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\HttpRequestLifecycleServiceAccessRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HttpRequestLifecycleServiceAccessRule>
 */
final class HttpRequestLifecycleServiceAccessRuleTest extends RuleTestCase
{
    private PathPolicy $pathPolicy;

    public function testReportsHttpLifecycleServiceAccess(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/http-request-lifecycle-service-access.php'],
            [
                [HttpRequestLifecycleServiceAccessRule::MESSAGE, 16],
                [HttpRequestLifecycleServiceAccessRule::MESSAGE, 17],
                [HttpRequestLifecycleServiceAccessRule::MESSAGE, 18],
            ],
        );
    }

    public function testAllowsInternalHttpLifecycleServiceAccess(): void
    {
        $file = __DIR__ . '/Fixtures/http-request-lifecycle-service-access.php';
        $this->pathPolicy = new PathPolicy(internalPaths: [$file]);

        $this->analyse([$file], []);
    }

    protected function setUp(): void
    {
        $this->pathPolicy = new PathPolicy();
    }

    protected function getRule(): Rule
    {
        return new HttpRequestLifecycleServiceAccessRule($this->pathPolicy);
    }
}

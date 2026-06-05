<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\LensRequiresBundleRule;
use Phalanx\Http\Testing\Lens;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<LensRequiresBundleRule>
 */
final class LensRequiresBundleRuleTest extends RuleTestCase
{
    public function testNoErrorWhenBundleDeclaresAccessedLens(): void
    {
        $this->analyse(
            [__DIR__ . '/../../Integration/Fixtures/LensRequiresBundle/HappyPath.php'],
            [],
        );
    }

    public function testErrorWhenAccessedLensHasNoMatchingBundle(): void
    {
        $this->analyse(
            [__DIR__ . '/../../Integration/Fixtures/LensRequiresBundle/MissingBundle.php'],
            [
                [
                    sprintf(
                        'Property $app->http returns %s which requires a ServiceBundle'
                        . ' whose static::lens() declares it.'
                        . ' None of the bundles passed to testApp() include this lens.',
                        \Phalanx\Http\Testing\Lens::class,
                    ),
                    25,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new LensRequiresBundleRule();
    }
}

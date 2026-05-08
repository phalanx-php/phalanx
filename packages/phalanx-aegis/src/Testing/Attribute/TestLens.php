<?php

declare(strict_types=1);

namespace Phalanx\Testing\Attribute;

use Attribute;
use Phalanx\Testing\TestLens as TestLensContract;
use Phalanx\Testing\TestLensFactory;

/**
 * Declares a class as a TestApp lens and supplies the metadata the codegen
 * plugin needs to emit a typed property accessor on TestApp.
 *
 * Applied to the lens class itself (not its factory) so that all lens
 * metadata lives in one place. The codegen plugin reflects this attribute
 * to produce one property hook per lens in the generated TestAppAccessors
 * trait.
 *
 * @see \Phalanx\Testing\TestApp
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TestLens
{
    /**
     * @param string                          $accessor Property name on TestApp (e.g. 'http', 'console', 'ledger').
     * @param class-string<TestLensContract>  $returns  Lens class returned by the accessor.
     * @param class-string<TestLensFactory>   $factory  Factory class used to construct the lens.
     * @param list<class-string>              $requires Service IDs that must be bound for the lens to function.
     *                                                  Surfaced by the LensRequiresBundleRule PHPStan check.
     */
    public function __construct(
        public string $accessor,
        public string $returns,
        public string $factory,
        public array $requires = [],
    ) {
    }
}

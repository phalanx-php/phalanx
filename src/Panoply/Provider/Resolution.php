<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

/**
 * Result of resolving a provider by alias. Carries the matched Config
 * and the specific Model within it. Replaces the previous tuple-as-array
 * return shape from {@see Registry::byModelAlias()}.
 *
 * Final — sealed result type with two fields.
 */
final class Resolution
{
    public function __construct(
        private(set) Config $config,
        private(set) Config\Model $model,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Http\Contract\RouteValidator;
use Phalanx\Http\RequestContext;

/**
 * Test fixture validator: always returns a known error.
 * Used to verify HasValidators wiring runs validators before the handler executes.
 */
final class AlwaysFailValidator implements RouteValidator
{
    public function validate(object|null $input, RequestContext $ctx): array
    {
        return ['test_field' => ['validator ran']];
    }
}

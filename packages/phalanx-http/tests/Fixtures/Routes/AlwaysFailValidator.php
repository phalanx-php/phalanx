<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Http\RequestScope;
use Phalanx\Http\RouteValidator;
use Phalanx\Http\ValidationException;

/**
 * Test fixture validator: always throws a ValidationException with a known
 * error key. Used to verify HasValidators wiring runs validators before the
 * handler executes.
 */
final class AlwaysFailValidator implements RouteValidator
{
    public function validate(RequestScope $scope): void
    {
        throw ValidationException::single('test_field', 'validator ran');
    }
}

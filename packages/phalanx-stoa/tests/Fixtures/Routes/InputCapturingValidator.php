<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Stoa\RequestScope;

/**
 * Test fixture validator: records the input object it receives and always fails.
 * Used to verify validators receive the hydrated DTO, not the raw input.
 */
final class InputCapturingValidator implements RouteValidator
{
    /** The last input value passed to validate(). Null means not yet called. */
    public static ?object $capturedInput = null;

    public static function reset(): void
    {
        self::$capturedInput = null;
    }

    public function validate(object|null $input, RequestScope $scope): array
    {
        self::$capturedInput = $input;
        return ['captured' => ['validator received dto']];
    }
}

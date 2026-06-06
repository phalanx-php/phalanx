<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Http\Contract\RouteValidator;
use Phalanx\Http\RequestContext;

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

    public function validate(RequestContext $ctx, object|null $input): array
    {
        self::$capturedInput = $input;
        return ['captured' => ['validator received dto']];
    }
}

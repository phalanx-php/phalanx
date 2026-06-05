<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Http\Contract\HasValidators;
use Phalanx\Http\Contract\RouteValidator;
use Phalanx\Http\RequestContext;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: handler with a DTO input parameter AND validators.
 * Used to verify that validators receive the hydrated DTO from InputHydrator,
 * not null, when the handler declares an input parameter.
 */
final class ValidatedInputHandler implements Scopeable, HasValidators
{
    /** @var list<class-string<RouteValidator>> */
    public array $validators {
        get => [InputCapturingValidator::class];
    }

    /** @return array{should_not_run: bool} */
    public function __invoke(RequestContext $ctx, SimpleInputDto $input): array
    {
        return ['should_not_run' => true];
    }
}

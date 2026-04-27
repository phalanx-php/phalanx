<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Stoa\Contract\HasValidators;
use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Stoa\RequestScope;
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
    public function __invoke(RequestScope $scope, SimpleInputDto $input): array
    {
        return ['should_not_run' => true];
    }
}

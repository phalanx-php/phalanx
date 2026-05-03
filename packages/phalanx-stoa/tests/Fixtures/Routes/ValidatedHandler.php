<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\Scope;
use Phalanx\Stoa\Contract\HasValidators;
use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Task\Scopeable;

/**
 * Test fixture: declares a single validator that always fails. Used to
 * verify the dispatcher resolves and runs validators before the handler.
 */
final class ValidatedHandler implements Scopeable, HasValidators
{
    /** @var list<class-string<RouteValidator>> */
    public array $validators {
        get => [AlwaysFailValidator::class];
    }

    /** @return array{should_not_run: bool} */
    public function __invoke(Scope $scope): array
    {
        return ['should_not_run' => true];
    }
}

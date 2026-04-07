<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Http\HasValidators;
use Phalanx\Http\RouteValidator;
use Phalanx\Scope;
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

<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator;

use Phalanx\Auth\AuthorizationException;
use Phalanx\Stoa\AuthRequestScope;
use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Stoa\RequestScope;

/**
 * Route validator that requires the authenticated user to hold a specific ability.
 *
 * Throws AuthorizationException (403) rather than returning field errors --
 * authorization failures are a structural rejection, not a field-level
 * validation problem. The runner's ToResponse handling converts this to
 * the appropriate HTTP response.
 *
 * Requires the scope to be an AuthRequestScope. If the scope is not
 * authenticated, throws AuthorizationException. Apply Authenticate middleware
 * before routes that use this validator.
 */
final class RequireAbility implements RouteValidator
{
    public function __construct(private readonly string $ability)
    {
    }

    public function validate(object|null $input, RequestScope $scope): array
    {
        if (!$scope instanceof AuthRequestScope || !$scope->auth->can($this->ability)) {
            throw new AuthorizationException(
                "Requires ability: {$this->ability}",
            );
        }

        return [];
    }
}

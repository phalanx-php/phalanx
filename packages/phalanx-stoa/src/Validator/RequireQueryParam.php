<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator;

use Phalanx\Stoa\Contract\RouteValidator;
use Phalanx\Stoa\RequestScope;

/**
 * Route validator that requires a specific query parameter to be present and
 * non-empty. Returns a field error if the parameter is missing or blank.
 */
final class RequireQueryParam implements RouteValidator
{
    public function __construct(private readonly string $param)
    {
    }

    public function validate(object|null $input, RequestScope $scope): array
    {
        $value = $scope->query->get($this->param);

        if ($value === null || $value === '') {
            return [$this->param => ["Query parameter '{$this->param}' is required"]];
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Http\Validator;

use Phalanx\Http\Contract\RouteValidator;
use Phalanx\Http\RequestScope;

/**
 * Route validator that requires a specific query parameter to be present and
 * non-empty. Returns a field error if the parameter is missing or blank.
 */
final class RequireQueryParam implements RouteValidator
{
    public function __construct(private readonly string $param) {}

    public function validate(object|null $input, RequestScope $scope): array
    {
        $value = $scope->query->get($this->param);

        if ($value === null || $value === '') {
            return [$this->param => ["Query parameter '{$this->param}' is required"]];
        }

        return [];
    }
}

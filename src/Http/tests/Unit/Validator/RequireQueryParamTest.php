<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Validator;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Validator\RequireQueryParam;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequireQueryParamTest extends PhalanxTestCase
{
    #[Test]
    public function returns_empty_when_param_present(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $inner): array {
            $scope = self::createRequestContext($inner, ['page' => '1']);
            $v = new RequireQueryParam('page');

            return $v->validate($scope, null);
        });

        $this->assertSame([], $result);
    }

    #[Test]
    public function returns_error_when_param_missing(): void
    {
        $errors = $this->scope->run(static function (ExecutionScope $inner): array {
            $scope = self::createRequestContext($inner, []);
            $v = new RequireQueryParam('page');

            return $v->validate($scope, null);
        });

        $this->assertArrayHasKey('page', $errors);
        $this->assertStringContainsString('page', $errors['page'][0]);
    }

    #[Test]
    public function returns_error_when_param_empty_string(): void
    {
        $errors = $this->scope->run(static function (ExecutionScope $inner): array {
            $scope = self::createRequestContext($inner, ['page' => '']);
            $v = new RequireQueryParam('page');

            return $v->validate($scope, null);
        });

        $this->assertArrayHasKey('page', $errors);
    }

    /** @param array<string, string> $query */
    private static function createRequestContext(ExecutionScope $inner, array $query): ExecutionContext
    {
        $request = (new ServerRequest('GET', '/test'))->withQueryParams($query);

        return new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams($query),
            new RouteConfig(),
        );
    }
}

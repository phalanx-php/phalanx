<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Validator;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Validator\RequireQueryParam;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequireQueryParamTest extends PhalanxTestCase
{
    #[Test]
    public function returns_empty_when_param_present(): void
    {
        $scope = $this->createScope(['page' => '1']);
        $v = new RequireQueryParam('page');

        $this->assertSame([], $v->validate(null, $scope));
    }

    #[Test]
    public function returns_error_when_param_missing(): void
    {
        $scope = $this->createScope([]);
        $v = new RequireQueryParam('page');

        $errors = $v->validate(null, $scope);

        $this->assertArrayHasKey('page', $errors);
        $this->assertStringContainsString('page', $errors['page'][0]);
    }

    #[Test]
    public function returns_error_when_param_empty_string(): void
    {
        $scope = $this->createScope(['page' => '']);
        $v = new RequireQueryParam('page');

        $errors = $v->validate(null, $scope);

        $this->assertArrayHasKey('page', $errors);
    }

    /** @param array<string, string> $query */
    private function createScope(array $query): ExecutionContext
    {
        $inner = $this->application()->createScope();
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

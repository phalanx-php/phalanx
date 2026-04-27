<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Validator;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\ValidationException;
use Phalanx\Stoa\Validator\RequireQueryParam;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequireQueryParamTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

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
        $inner = $this->app->createScope();
        $request = new ServerRequest('GET', '/test', [], null, '1.1', [], [], $query);

        return new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams($query),
            new RouteConfig(),
        );
    }
}

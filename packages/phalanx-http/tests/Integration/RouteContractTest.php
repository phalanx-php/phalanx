<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Application;
use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Http\Response\NoContent;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\ValidationException;
use Phalanx\Tests\Http\Fixtures\CreateTaskInput;
use Phalanx\Tests\Http\Fixtures\ListTasksQuery;
use Phalanx\Tests\Http\Fixtures\TaskPriority;
use Phalanx\Tests\Http\Fixtures\TaskResource;
use Phalanx\Tests\Http\Fixtures\TaskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class RouteContractTest extends TestCase
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
    public function post_route_hydrates_input_from_body(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => new Route(fn: static function (ExecutionScope $scope, CreateTaskInput $input): Created {
                return new Created(new TaskResource(
                    id: 1,
                    title: $input->title,
                    description: $input->description,
                    priority: $input->priority,
                    status: TaskStatus::Pending,
                ));
            }),
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => 'Build phalanx-ui',
            'priority' => 'high',
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertInstanceOf(Created::class, $result);
        $this->assertInstanceOf(TaskResource::class, $result->data);
        $this->assertSame('Build phalanx-ui', $result->data->title);
        $this->assertSame(TaskPriority::High, $result->data->priority);
        $this->assertNull($result->data->description);
    }

    #[Test]
    public function get_route_hydrates_query_params(): void
    {
        $group = RouteGroup::of([
            'GET /tasks' => new Route(fn: static function (ExecutionScope $scope, ListTasksQuery $query): array {
                return [
                    'page' => $query->page,
                    'limit' => $query->limit,
                    'status' => $query->status?->value,
                    'search' => $query->search,
                ];
            }),
        ]);

        $request = $this->createRequest('GET', '/tasks', query: [
            'page' => '2',
            'limit' => '10',
            'status' => 'done',
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame(2, $result['page']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame('done', $result['status']);
        $this->assertNull($result['search']);
    }

    #[Test]
    public function handler_with_no_input_still_works(): void
    {
        $group = RouteGroup::of([
            'GET /health' => new Route(fn: static fn() => ['status' => 'ok']),
        ]);

        $request = $this->createRequest('GET', '/health');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame(['status' => 'ok'], $result);
    }

    #[Test]
    public function missing_required_field_throws_validation_exception(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => new Route(fn: static function (ExecutionScope $scope, CreateTaskInput $input): Created {
                return new Created($input);
            }),
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'description' => 'no title provided',
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
        }
    }

    #[Test]
    public function invalid_enum_throws_validation_exception(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => new Route(fn: static function (ExecutionScope $scope, CreateTaskInput $input): Created {
                return new Created($input);
            }),
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => 'Test',
            'priority' => 'urgent',
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priority', $e->errors);
        }
    }

    #[Test]
    public function validatable_dto_errors_throw_before_handler(): void
    {
        $handlerCalled = false;

        $group = RouteGroup::of([
            'POST /tasks' => new Route(fn: static function (ExecutionScope $scope, CreateTaskInput $input) use (&$handlerCalled): Created {
                $handlerCalled = true;
                return new Created($input);
            }),
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => '',
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertFalse($handlerCalled, 'Handler should not run when validation fails');
            $this->assertArrayHasKey('title', $e->errors);
        }
    }

    #[Test]
    public function void_handler_returns_null(): void
    {
        $group = RouteGroup::of([
            'DELETE /tasks/{id}' => new Route(fn: static function (ExecutionScope $scope): NoContent {
                return new NoContent();
            }),
        ]);

        $request = $this->createRequest('DELETE', '/tasks/42');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertInstanceOf(NoContent::class, $result);
    }

    private function createRequest(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $body = json_encode($json, JSON_THROW_ON_ERROR);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturn(
            $method !== 'GET' ? 'application/json' : '',
        );

        return $request;
    }
}

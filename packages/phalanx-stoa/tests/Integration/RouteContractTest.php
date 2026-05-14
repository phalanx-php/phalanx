<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Application;
use Phalanx\Stoa\Response\Created;
use Phalanx\Stoa\Response\NoContent;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\ValidationException;
use Phalanx\Tests\Stoa\Fixtures\Routes\CreateTaskEcho;
use Phalanx\Tests\Stoa\Fixtures\Routes\CreateTaskHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\DeleteTaskNoContent;
use Phalanx\Tests\Stoa\Fixtures\Routes\HealthCheck;
use Phalanx\Tests\Stoa\Fixtures\Routes\ListTasksHandler;
use Phalanx\Tests\Stoa\Fixtures\TaskPriority;
use Phalanx\Tests\Stoa\Fixtures\TaskResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class RouteContractTest extends TestCase
{
    private Application $app;

    #[Test]
    public function post_route_hydrates_input_from_body(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => CreateTaskHandler::class,
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => 'Build phalanx-ui',
            'priority' => 'high',
        ]);

        $result = $this->dispatch($group, $request);

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
            'GET /tasks' => ListTasksHandler::class,
        ]);

        $request = $this->createRequest('GET', '/tasks', query: [
            'page' => '2',
            'limit' => '10',
            'status' => 'done',
        ]);

        $result = $this->dispatch($group, $request);

        $this->assertSame(2, $result['page']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame('done', $result['status']);
        $this->assertNull($result['search']);
    }

    #[Test]
    public function handler_with_no_input_still_works(): void
    {
        $group = RouteGroup::of([
            'GET /health' => HealthCheck::class,
        ]);

        $request = $this->createRequest('GET', '/health');

        $result = $this->dispatch($group, $request);

        $this->assertSame(['status' => 'ok'], $result);
    }

    #[Test]
    public function handler_with_no_parameters_still_works(): void
    {
        // HealthCheck declares __invoke() with zero parameters -- not even a
        // scope. InputHydrator must return [] and the invoker must call the
        // handler with no arguments rather than injecting a scope it doesn't
        // accept.
        $group = RouteGroup::of([
            'GET /ping' => HealthCheck::class,
        ]);

        $request = $this->createRequest('GET', '/ping');

        $result = $this->dispatch($group, $request);

        $this->assertSame(['status' => 'ok'], $result);
    }

    #[Test]
    public function missing_required_field_throws_validation_exception(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => CreateTaskEcho::class,
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'description' => 'no title provided',
        ]);

        try {
            $this->dispatch($group, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
        }
    }

    #[Test]
    public function invalid_enum_throws_validation_exception(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => CreateTaskEcho::class,
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => 'Test',
            'priority' => 'urgent',
        ]);

        try {
            $this->dispatch($group, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priority', $e->errors);
        }
    }

    #[Test]
    public function validatable_dto_errors_throw_before_handler(): void
    {
        $group = RouteGroup::of([
            'POST /tasks' => CreateTaskEcho::class,
        ]);

        $request = $this->createRequest('POST', '/tasks', json: [
            'title' => '',
        ]);

        try {
            $this->dispatch($group, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
        }
    }

    #[Test]
    public function void_handler_returns_null(): void
    {
        $group = RouteGroup::of([
            'DELETE /tasks/{id}' => DeleteTaskNoContent::class,
        ]);

        $request = $this->createRequest('DELETE', '/tasks/42');

        $result = $this->dispatch($group, $request);

        $this->assertInstanceOf(NoContent::class, $result);
    }

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $query
     */
    private function createRequest(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $body = json_encode($json, JSON_THROW_ON_ERROR);

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturn(
            $method !== 'GET' ? 'application/json' : '',
        );

        return $request;
    }

    private function dispatch(RouteGroup $group, ServerRequestInterface $request): mixed
    {
        return $group->dispatch($request, $this->app->createScope());
    }
}

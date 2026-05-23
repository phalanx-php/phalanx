<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration\OpenApi;

use Phalanx\Stoa\OpenApi\OpenApiGenerator;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Tests\Stoa\Fixtures\Routes\CreateTaskEcho;
use Phalanx\Tests\Stoa\Fixtures\Routes\DeleteTaskNoContent;
use Phalanx\Tests\Stoa\Fixtures\Routes\DeleteTaskVoid;
use Phalanx\Tests\Stoa\Fixtures\Routes\DescribedListTasks;
use Phalanx\Tests\Stoa\Fixtures\Routes\HealthCheck;
use Phalanx\Tests\Stoa\Fixtures\Routes\ListTasksEmpty;
use Phalanx\Tests\Stoa\Fixtures\Routes\ShowTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    #[Test]
    public function generates_basic_spec_structure(): void
    {
        $routes = RouteGroup::of([
            'GET /health' => HealthCheck::class,
        ]);

        $generator = new OpenApiGenerator(title: 'Test API', version: '2.0.0');
        $spec = $generator->generate($routes);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('Test API', $spec['info']['title']);
        $this->assertSame('2.0.0', $spec['info']['version']);
        $this->assertArrayHasKey('/health', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/health']);
    }

    #[Test]
    public function generates_post_route_with_request_body(): void
    {
        $routes = RouteGroup::of([
            'POST /tasks' => CreateTaskEcho::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks']['post'];

        $this->assertArrayHasKey('requestBody', $operation);
        $this->assertTrue($operation['requestBody']['required']);

        $schema = $operation['requestBody']['content']['application/json']['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertContains('title', $schema['required']);

        $this->assertArrayHasKey('201', $operation['responses']);
        $this->assertArrayHasKey('422', $operation['responses']);
    }

    #[Test]
    public function generates_get_route_with_query_params(): void
    {
        $routes = RouteGroup::of([
            'GET /tasks' => ListTasksEmpty::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks']['get'];

        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertArrayHasKey('parameters', $operation);

        $paramNames = array_column($operation['parameters'], 'name');
        $this->assertContains('page', $paramNames);
        $this->assertContains('limit', $paramNames);
        $this->assertContains('status', $paramNames);
        $this->assertContains('search', $paramNames);
    }

    #[Test]
    public function generates_path_parameters(): void
    {
        $routes = RouteGroup::of([
            'GET /tasks/{id}' => ShowTask::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks/{id}']['get'];

        $this->assertArrayHasKey('parameters', $operation);
        $pathParam = $operation['parameters'][0];
        $this->assertSame('id', $pathParam['name']);
        $this->assertSame('path', $pathParam['in']);
        $this->assertTrue($pathParam['required']);

        $this->assertArrayHasKey('404', $operation['responses']);
    }

    #[Test]
    public function generates_204_for_void_return(): void
    {
        $routes = RouteGroup::of([
            'DELETE /tasks/{id}' => DeleteTaskVoid::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks/{id}']['delete'];

        $this->assertArrayHasKey('204', $operation['responses']);
        $this->assertSame('No Content', $operation['responses']['204']['description']);
    }

    #[Test]
    public function generates_204_for_no_content_return(): void
    {
        $routes = RouteGroup::of([
            'DELETE /tasks/{id}' => DeleteTaskNoContent::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks/{id}']['delete'];

        $this->assertArrayHasKey('204', $operation['responses']);
    }

    #[Test]
    public function includes_summary_and_tags_from_self_described(): void
    {
        $routes = RouteGroup::of([
            'GET /tasks' => DescribedListTasks::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks']['get'];

        $this->assertSame('List all tasks with filtering', $operation['summary']);
        $this->assertSame(['tasks'], $operation['tags']);
        $this->assertArrayHasKey('parameters', $operation);
    }

    #[Test]
    public function generates_response_schema_for_typed_return(): void
    {
        $routes = RouteGroup::of([
            'GET /tasks/{id}' => ShowTask::class,
        ]);

        $spec = (new OpenApiGenerator())->generate($routes);
        $operation = $spec['paths']['/tasks/{id}']['get'];

        $this->assertArrayHasKey('200', $operation['responses']);
        $responseSchema = $operation['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('object', $responseSchema['type']);
        $this->assertArrayHasKey('id', $responseSchema['properties']);
        $this->assertArrayHasKey('title', $responseSchema['properties']);
        $this->assertArrayHasKey('status', $responseSchema['properties']);
    }
}

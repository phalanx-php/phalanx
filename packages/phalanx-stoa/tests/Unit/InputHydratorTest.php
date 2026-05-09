<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Stoa\Contract\InputHydrator;
use Phalanx\Stoa\Contract\InputMeta;
use Phalanx\Stoa\Contract\InputSource;
use Phalanx\Stoa\ValidationException;
use Phalanx\Tests\Stoa\Fixtures\CreateTaskInput;
use Phalanx\Tests\Stoa\Fixtures\ListTasksQuery;
use Phalanx\Tests\Stoa\Fixtures\Routes\CreateTaskHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\HealthCheck;
use Phalanx\Tests\Stoa\Fixtures\Routes\ListTasksHandler;
use Phalanx\Tests\Stoa\Fixtures\TaskPriority;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputHydratorTest extends TestCase
{
    #[Test]
    public function meta_returns_null_for_scope_only_handler(): void
    {
        $this->assertNull(InputHydrator::meta(HealthCheck::class));
    }

    #[Test]
    public function meta_detects_typed_input_parameter(): void
    {
        $meta = InputHydrator::meta(CreateTaskHandler::class);

        $this->assertInstanceOf(InputMeta::class, $meta);
        $this->assertSame(CreateTaskInput::class, $meta->inputClass);
        $this->assertSame('input', $meta->paramName);
    }

    #[Test]
    public function meta_detects_query_type_parameter(): void
    {
        $meta = InputHydrator::meta(ListTasksHandler::class);

        $this->assertInstanceOf(InputMeta::class, $meta);
        $this->assertSame(ListTasksQuery::class, $meta->inputClass);
        $this->assertSame('query', $meta->paramName);
    }

    #[Test]
    public function input_source_from_post_is_body(): void
    {
        $this->assertSame(InputSource::Body, InputSource::fromMethod('POST'));
        $this->assertSame(InputSource::Body, InputSource::fromMethod('PUT'));
        $this->assertSame(InputSource::Body, InputSource::fromMethod('PATCH'));
    }

    #[Test]
    public function input_source_from_get_is_query(): void
    {
        $this->assertSame(InputSource::Query, InputSource::fromMethod('GET'));
        $this->assertSame(InputSource::Query, InputSource::fromMethod('DELETE'));
        $this->assertSame(InputSource::Query, InputSource::fromMethod('HEAD'));
    }

    #[Test]
    public function hydrates_dto_with_all_fields(): void
    {
        $data = ['title' => 'Test Task', 'description' => 'A description', 'priority' => 'high'];

        $scope = $this->mockScope('POST', $data);
        [, $dto] = InputHydrator::resolve(CreateTaskHandler::class, $scope);

        $this->assertInstanceOf(CreateTaskInput::class, $dto);
        $this->assertSame('Test Task', $dto->title);
        $this->assertSame('A description', $dto->description);
        $this->assertSame(TaskPriority::High, $dto->priority);
    }

    #[Test]
    public function hydrates_dto_with_defaults(): void
    {
        $data = ['title' => 'Minimal'];

        $scope = $this->mockScope('POST', $data);
        [, $dto] = InputHydrator::resolve(CreateTaskHandler::class, $scope);

        $this->assertInstanceOf(CreateTaskInput::class, $dto);
        $this->assertSame('Minimal', $dto->title);
        $this->assertNull($dto->description);
        $this->assertSame(TaskPriority::Normal, $dto->priority);
    }

    #[Test]
    public function resolve_hydrates_dto_before_return(): void
    {
        $data = ['title' => 'Test Task'];
        $scope = $this->mockScope('POST', $data);

        [, $dto] = InputHydrator::resolve(CreateTaskHandler::class, $scope);

        $this->assertInstanceOf(CreateTaskInput::class, $dto);
        $this->assertSame('Test Task', $dto->title);
    }

    #[Test]
    public function resolve_validates_before_return(): void
    {
        $data = ['title' => ''];
        $scope = $this->mockScope('POST', $data);

        $this->expectException(ValidationException::class);
        InputHydrator::resolve(CreateTaskHandler::class, $scope);
    }

    #[Test]
    public function throws_for_missing_required_field(): void
    {
        $data = ['description' => 'no title'];
        $scope = $this->mockScope('POST', $data);

        $this->expectException(ValidationException::class);
        try {
            InputHydrator::resolve(CreateTaskHandler::class, $scope);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertSame('This field is required', $e->errors['title'][0]);
            throw $e;
        }
    }

    #[Test]
    public function throws_for_invalid_enum_value(): void
    {
        $data = ['title' => 'Test', 'priority' => 'urgent'];
        $scope = $this->mockScope('POST', $data);

        $this->expectException(ValidationException::class);
        try {
            InputHydrator::resolve(CreateTaskHandler::class, $scope);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priority', $e->errors);
            $this->assertStringContainsString('urgent', $e->errors['priority'][0]);
            $this->assertStringContainsString('low, normal, high, critical', $e->errors['priority'][0]);
            throw $e;
        }
    }

    #[Test]
    public function nullable_field_accepts_null(): void
    {
        $data = ['title' => 'Test', 'description' => null];
        $scope = $this->mockScope('POST', $data);

        [, $dto] = InputHydrator::resolve(CreateTaskHandler::class, $scope);

        $this->assertNull($dto->description);
    }

    #[Test]
    public function hydrates_query_dto_with_int_coercion(): void
    {
        $data = ['page' => '3', 'limit' => '50'];
        $scope = $this->mockScope('GET', $data);

        [, $dto] = InputHydrator::resolve(ListTasksHandler::class, $scope);

        $this->assertInstanceOf(ListTasksQuery::class, $dto);
        $this->assertSame(3, $dto->page);
        $this->assertSame(50, $dto->limit);
        $this->assertNull($dto->status);
        $this->assertNull($dto->search);
    }

    #[Test]
    public function runs_validatable_after_hydration(): void
    {
        $data = ['title' => ''];
        $scope = $this->mockScope('POST', $data);

        $this->expectException(ValidationException::class);
        try {
            InputHydrator::resolve(CreateTaskHandler::class, $scope);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertSame('Title is required', $e->errors['title'][0]);
            throw $e;
        }
    }

    private function mockScope(string $method, array $data): \Phalanx\Stoa\RequestScope
    {
        $inner = $this->createMock(\Phalanx\Scope\ExecutionScope::class);
        $request = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);

        if ($method === 'POST') {
            $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $stream->method('__toString')->willReturn(json_encode($data));
            $request->method('getBody')->willReturn($stream);
        } else {
            $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $stream->method('__toString')->willReturn('');
            $request->method('getBody')->willReturn($stream);
        }

        return new \Phalanx\Stoa\ExecutionContext(
            $inner,
            $request,
            new \Phalanx\Stoa\RouteParams([]),
            new \Phalanx\Stoa\QueryParams($method === 'GET' ? $data : []),
            \Phalanx\Stoa\RouteConfig::compile('/')
        );
    }
}

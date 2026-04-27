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

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');
        $dto = $ref->invoke(null, CreateTaskInput::class, $data);

        $this->assertInstanceOf(CreateTaskInput::class, $dto);
        $this->assertSame('Test Task', $dto->title);
        $this->assertSame('A description', $dto->description);
        $this->assertSame(TaskPriority::High, $dto->priority);
    }

    #[Test]
    public function hydrates_dto_with_defaults(): void
    {
        $data = ['title' => 'Minimal'];

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');
        $dto = $ref->invoke(null, CreateTaskInput::class, $data);

        $this->assertInstanceOf(CreateTaskInput::class, $dto);
        $this->assertSame('Minimal', $dto->title);
        $this->assertNull($dto->description);
        $this->assertSame(TaskPriority::Normal, $dto->priority);
    }

    #[Test]
    public function throws_for_missing_required_field(): void
    {
        $data = ['description' => 'no title'];

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');

        try {
            $ref->invoke(null, CreateTaskInput::class, $data);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertSame('This field is required', $e->errors['title'][0]);
        }
    }

    #[Test]
    public function throws_for_invalid_enum_value(): void
    {
        $data = ['title' => 'Test', 'priority' => 'urgent'];

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');

        try {
            $ref->invoke(null, CreateTaskInput::class, $data);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priority', $e->errors);
            $this->assertStringContainsString('urgent', $e->errors['priority'][0]);
            $this->assertStringContainsString('low, normal, high, critical', $e->errors['priority'][0]);
        }
    }

    #[Test]
    public function nullable_field_accepts_null(): void
    {
        $data = ['title' => 'Test', 'description' => null];

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');
        $dto = $ref->invoke(null, CreateTaskInput::class, $data);

        $this->assertNull($dto->description);
    }

    #[Test]
    public function hydrates_query_dto_with_int_coercion(): void
    {
        $data = ['page' => '3', 'limit' => '50'];

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');
        $dto = $ref->invoke(null, ListTasksQuery::class, $data);

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

        $ref = new \ReflectionMethod(InputHydrator::class, 'hydrate');

        try {
            $ref->invoke(null, CreateTaskInput::class, $data);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertSame('Title is required', $e->errors['title'][0]);
        }
    }
}

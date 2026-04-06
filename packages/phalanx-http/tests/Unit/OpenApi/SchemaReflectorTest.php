<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit\OpenApi;

use Phalanx\Http\OpenApi\SchemaReflector;
use Phalanx\Http\Response\Created;
use Phalanx\Http\Response\NoContent;
use Phalanx\Tests\Http\Fixtures\CreateTaskInput;
use Phalanx\Tests\Http\Fixtures\ListTasksQuery;
use Phalanx\Tests\Http\Fixtures\TaskResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;

final class SchemaReflectorTest extends TestCase
{
    #[Test]
    public function class_schema_extracts_required_and_optional_fields(): void
    {
        $schema = SchemaReflector::classSchema(CreateTaskInput::class);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertArrayHasKey('priority', $schema['properties']);

        $this->assertContains('title', $schema['required']);
        $this->assertNotContains('description', $schema['required'] ?? []);
        $this->assertNotContains('priority', $schema['required'] ?? []);
    }

    #[Test]
    public function class_schema_handles_string_type(): void
    {
        $schema = SchemaReflector::classSchema(CreateTaskInput::class);

        $this->assertSame('string', $schema['properties']['title']['type']);
    }

    #[Test]
    public function class_schema_handles_nullable_type(): void
    {
        $schema = SchemaReflector::classSchema(CreateTaskInput::class);

        $this->assertTrue($schema['properties']['description']['nullable'] ?? false);
    }

    #[Test]
    public function class_schema_handles_enum_type(): void
    {
        $schema = SchemaReflector::classSchema(CreateTaskInput::class);

        $this->assertSame('string', $schema['properties']['priority']['type']);
        $this->assertSame(['low', 'normal', 'high', 'critical'], $schema['properties']['priority']['enum']);
    }

    #[Test]
    public function class_schema_includes_defaults(): void
    {
        $schema = SchemaReflector::classSchema(CreateTaskInput::class);

        $this->assertSame('normal', $schema['properties']['priority']['default']);
    }

    #[Test]
    public function class_schema_handles_int_type(): void
    {
        $schema = SchemaReflector::classSchema(ListTasksQuery::class);

        $this->assertSame('integer', $schema['properties']['page']['type']);
        $this->assertSame('integer', $schema['properties']['limit']['type']);
    }

    #[Test]
    public function unwrap_void_returns_204(): void
    {
        $type = $this->createNamedType('void');

        [$status, $schema] = SchemaReflector::unwrapResponseWrapper($type);

        $this->assertSame(204, $status);
        $this->assertNull($schema);
    }

    #[Test]
    public function unwrap_no_content_returns_204(): void
    {
        $type = $this->createNamedType(NoContent::class);

        [$status, $schema] = SchemaReflector::unwrapResponseWrapper($type);

        $this->assertSame(204, $status);
        $this->assertNull($schema);
    }

    #[Test]
    public function unwrap_created_returns_201(): void
    {
        $type = $this->createNamedType(Created::class);

        [$status, $schema] = SchemaReflector::unwrapResponseWrapper($type);

        $this->assertSame(201, $status);
    }

    #[Test]
    public function unwrap_plain_class_returns_200_with_schema(): void
    {
        $type = $this->createNamedType(TaskResource::class);

        [$status, $schema] = SchemaReflector::unwrapResponseWrapper($type);

        $this->assertSame(200, $status);
        $this->assertNotNull($schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('id', $schema['properties']);
    }

    private function createNamedType(string $name): ReflectionNamedType
    {
        $type = $this->createMock(ReflectionNamedType::class);
        $type->method('getName')->willReturn($name);
        $type->method('allowsNull')->willReturn(false);

        return $type;
    }
}

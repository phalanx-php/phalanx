<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Effect\Context as EffectContext;
use Phalanx\Agents\Effect\Outcome as EffectOutcome;
use Phalanx\Agents\Effect\Resolution;
use Phalanx\Agents\Tool\Param;
use Phalanx\Agents\Tool\SchemaGenerator;
use Phalanx\Agents\Tool\Tool;
use Phalanx\Scope\TaskScope;
use Phalanx\SelfDescribed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorAcceptanceTest extends TestCase
{
    #[Test]
    public function schemaForToolWithParamAttributesPassesStructureValidation(): void
    {
        $schema = SchemaGenerator::forTool(SearchTool::class);

        self::assertArrayHasKey('name', $schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('parameters', $schema);

        $params = $schema['parameters'];
        self::assertSame('object', $params['type']);
        self::assertArrayHasKey('properties', $params);
        self::assertArrayHasKey('required', $params);

        self::assertArrayHasKey('query', $params['properties']);
        self::assertSame('string', $params['properties']['query']['type']);
        self::assertContains('query', $params['required']);

        self::assertArrayHasKey('limit', $params['properties']);
        self::assertSame('integer', $params['properties']['limit']['type']);
        self::assertNotContains('limit', $params['required']);
    }

    #[Test]
    public function schemaForSelfDescribedToolUsesDescriptionPropertyHook(): void
    {
        $schema = SchemaGenerator::forTool(DescribedSearchTool::class);

        self::assertSame('Search the knowledge base for relevant content', $schema['description']);
    }

    #[Test]
    public function schemaForToolWithNoConstructorReturnsEmptyParameters(): void
    {
        $schema = SchemaGenerator::forTool(NoArgTool::class);

        self::assertSame('object', $schema['parameters']['type']);
        self::assertSame([], $schema['parameters']['properties']);
        self::assertSame([], $schema['parameters']['required']);
    }
}

final class SearchTool implements Tool
{
    public function __construct(
        #[Param(description: 'Search query string')]
        private(set) string $query,

        #[Param(description: 'Maximum results', required: false, default: 10)]
        private(set) int $limit = 10,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: []);
    }
}

final class DescribedSearchTool implements Tool, SelfDescribed
{
    public string $description { get => 'Search the knowledge base for relevant content'; }

    public function __construct(
        #[Param(description: 'Query')]
        private(set) string $query,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: []);
    }
}

final class NoArgTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: null);
    }
}

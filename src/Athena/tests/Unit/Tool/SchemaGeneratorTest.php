<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Tool;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\SchemaGenerator;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

final class SearchCorpus implements Tool
{
    public function __construct(
        #[Param('Search query text')]
        private(set) string $query,

        #[Param('Maximum results to return', required: false, default: 10)]
        private(set) int $limit,

        #[Param('Filter by document type', required: false)]
        private(set) bool $strict,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

final class NoParamTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool);
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

final class SchemaGeneratorTest extends TestCase
{
    #[Test]
    public function topLevelSchemaShapeIsCorrect(): void
    {
        $schema = SchemaGenerator::forTool(SearchCorpus::class);

        self::assertArrayHasKey('name', $schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('parameters', $schema);
        self::assertSame('SearchCorpus', $schema['name']);
        self::assertSame('SearchCorpus', $schema['description']);
    }

    #[Test]
    public function requiredParamAppearsInRequiredList(): void
    {
        $schema = SchemaGenerator::forTool(SearchCorpus::class);

        self::assertContains('query', $schema['parameters']['required']);
        self::assertNotContains('limit', $schema['parameters']['required']);
        self::assertNotContains('strict', $schema['parameters']['required']);
    }

    #[Test]
    public function phpTypesMapToJsonSchemaTypes(): void
    {
        $props = SchemaGenerator::forTool(SearchCorpus::class)['parameters']['properties'];

        self::assertSame('string', $props['query']['type']);
        self::assertSame('integer', $props['limit']['type']);
        self::assertSame('boolean', $props['strict']['type']);
    }

    #[Test]
    public function optionalParamDescriptionIsPreserved(): void
    {
        $props = SchemaGenerator::forTool(SearchCorpus::class)['parameters']['properties'];

        self::assertSame('Search query text', $props['query']['description']);
        self::assertSame('Maximum results to return', $props['limit']['description']);
        self::assertSame('Filter by document type', $props['strict']['description']);
    }

    #[Test]
    public function defaultValueAppearsOnOptionalParamWithNonNullDefault(): void
    {
        $props = SchemaGenerator::forTool(SearchCorpus::class)['parameters']['properties'];

        self::assertArrayHasKey('default', $props['limit']);
        self::assertSame(10, $props['limit']['default']);
    }

    #[Test]
    public function optionalParamWithNullDefaultHasNoDefaultKey(): void
    {
        $props = SchemaGenerator::forTool(SearchCorpus::class)['parameters']['properties'];

        self::assertArrayNotHasKey('default', $props['strict']);
    }

    #[Test]
    public function toolWithNoConstructorProducesEmptyParameters(): void
    {
        $schema = SchemaGenerator::forTool(NoParamTool::class);

        self::assertSame('object', $schema['parameters']['type']);
        self::assertSame([], $schema['parameters']['properties']);
        self::assertSame([], $schema['parameters']['required']);
    }

    #[Test]
    public function parametersObjectTypeIsAlwaysPresent(): void
    {
        $params = SchemaGenerator::forTool(SearchCorpus::class)['parameters'];

        self::assertSame('object', $params['type']);
    }
}

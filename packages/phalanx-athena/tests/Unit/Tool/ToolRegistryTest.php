<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Tool;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Testing\ScopeStub;
use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function registerAndFindTool(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', ReadFileTool::class);

        self::assertTrue($registry->has('read_file'));
        self::assertSame(ReadFileTool::class, $registry->find('read_file'));
    }

    #[Test]
    public function findReturnsNullForUnknown(): void
    {
        $registry = new ToolRegistry();

        self::assertFalse($registry->has('phantom'));
        self::assertNull($registry->find('phantom'));
    }

    #[Test]
    public function mergesBundleImmutably(): void
    {
        $registry = new ToolRegistry();
        $bundle = new ToolBundle(['read_file' => ReadFileTool::class]);

        $merged = $registry->merge($bundle);

        self::assertFalse($registry->has('read_file'));
        self::assertTrue($merged->has('read_file'));
    }

    #[Test]
    public function schemaProducesDeclarationsForAllTools(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', ReadFileTool::class);

        $schema = $registry->schema();

        self::assertCount(1, $schema);
        self::assertSame('read_file', $schema[0]['name']);
        self::assertArrayHasKey('parameters', $schema[0]);
    }

    #[Test]
    public function invokeConstructsAndCallsTool(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', ReadFileTool::class);

        $ctx = new EffectContext('act_1', 'inv_1', 'agent_1');
        $result = $registry->invoke(new ScopeStub(), 'read_file', $ctx, ['path' => '/tmp/test']);

        self::assertSame(Resolution::LocalTool, $result->resolution);
        self::assertSame('/tmp/test', $result->data);
    }

    #[Test]
    public function invokeThrowsForUnknownTool(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown tool: phantom');

        $registry->invoke(new ScopeStub(), 'phantom', new EffectContext('act_1', null, null));
    }

    #[Test]
    public function namesReturnsRegisteredToolNames(): void
    {
        $registry = new ToolRegistry();
        $registry->register('read_file', ReadFileTool::class);
        $registry->register('write_file', ReadFileTool::class);

        self::assertSame(['read_file', 'write_file'], $registry->names());
    }

    #[Test]
    public function bundleAddIsImmutable(): void
    {
        $bundle = new ToolBundle();
        $withTool = $bundle->add('read_file', ReadFileTool::class);

        self::assertSame([], $bundle->tools);
        self::assertCount(1, $withTool->tools);
    }
}

final class ReadFileTool implements Tool
{
    public function __construct(
        #[Param('File path to read')]
        private(set) string $path,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: $this->path);
    }
}

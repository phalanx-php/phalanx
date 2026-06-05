<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Tool;

use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Testing\ScopeStub;
use Phalanx\Agent\Tool\Param;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Agent\Tool\ToolExecutor;
use Phalanx\Agent\Tool\ToolRegistry;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolExecutorTest extends TestCase
{
    #[Test]
    public function successfulToolReturnsLocalToolOutcome(): void
    {
        $registry = new ToolRegistry();
        $registry->register('echo_value', EchoValueTool::class);

        $executor = new ToolExecutor($registry);
        $result = $executor(
            new ScopeStub(),
            self::makeRequest('echo_value', ['value' => 'hoplite']),
            self::makeContext(),
        );

        self::assertSame(Resolution::LocalTool, $result->resolution);
        self::assertSame('hoplite', $result->data);
        self::assertNull($result->error);
    }

    #[Test]
    public function toolExceptionPropagates(): void
    {
        $registry = new ToolRegistry();
        $registry->register('failing_tool', FailingTool::class);

        $executor = new ToolExecutor($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sparta burns');

        $executor(new ScopeStub(), self::makeRequest('failing_tool'), self::makeContext());
    }

    #[Test]
    public function unknownToolNamePropagatesException(): void
    {
        $registry = new ToolRegistry();

        $executor = new ToolExecutor($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/phantom/');

        $executor(new ScopeStub(), self::makeRequest('phantom'), self::makeContext());
    }

    /** @param array<string, mixed> $arguments */
    private static function makeRequest(string $effectId, array $arguments = []): Requested
    {
        return new Requested(
            id: 'cue_01',
            sequence: 1,
            activityId: 'act_01',
            invocationId: 'inv_01',
            agentId: 'agent_01',
            at: new \DateTimeImmutable(),
            effectId: $effectId,
            kind: Kind::Custom,
            summary: 'test invocation',
            arguments: $arguments,
        );
    }

    private static function makeContext(): EffectContext
    {
        return new EffectContext('act_01', 'inv_01', 'agent_01');
    }
}

final class EchoValueTool implements Tool
{
    public function __construct(
        #[Param('Value to echo back')]
        private(set) string $value,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: $this->value);
    }
}

final class FailingTool implements Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        throw new \RuntimeException('sparta burns');
    }
}

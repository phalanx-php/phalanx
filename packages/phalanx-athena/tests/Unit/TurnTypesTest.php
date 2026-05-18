<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\Config;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnTypesTest extends TestCase
{
    #[Test]
    public function outcomeKnowsTerminalStates(): void
    {
        self::assertFalse(Outcome::Continue->terminal());
        self::assertTrue(Outcome::Complete->terminal());
        self::assertTrue(Outcome::WaitingForApproval->terminal());
    }

    #[Test]
    public function configCanAdvanceInvocationNumber(): void
    {
        $config = new Config('act_1', Context::new(), 3);
        $next = $config->forInvocation(2);

        self::assertSame(2, $next->invocation);
        self::assertSame('act_1', $next->activityId);
    }

    #[Test]
    public function defaultBuilderCreatesPanoplyInvocation(): void
    {
        $builder = new DefaultBuilder();
        $config  = new Config('act_1', Context::new(), 3, 2);

        $log = Log::from([
            new Message(
                id: 'msg_1',
                sequence: 1,
                at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
                role: 'user',
                text: 'Summarize this.',
            ),
        ]);

        $invocation = $builder->build(new ScopeStub(), new TestAgent(), $log, $config);

        self::assertSame('act_1', $invocation->activityId);
        self::assertSame('athena-test-agent', $invocation->agentId);
        self::assertSame('Summarize the current activity.', $invocation->instructions);
        self::assertSame(2, $invocation->dynamicContext['invocation']);
        self::assertSame([['role' => 'user', 'content' => 'Summarize this.']], $invocation->dynamicContext['messages']);
        self::assertSame(1, $invocation->dynamicContext['conversation_record_count']);
    }

    #[Test]
    public function invocationCannotExceedMaxInvocations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config('act_1', Context::new(), maxInvocations: 1, invocation: 2);
    }
}

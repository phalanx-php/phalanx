<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Activity;
use Phalanx\Agents\Persistence\MemoryExecutionStore;
use Phalanx\Agents\Tests\Fixtures\SyncRuntimeFactory;
use Phalanx\Agents\Tests\Fixtures\TestAgent;
use Phalanx\Agents\Turn\DefaultBuilder;
use Phalanx\Agents\Turn\Loop;
use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PersistenceResumeTest extends PhalanxTestCase
{
    #[Test]
    public function suspendedActivityPersistsLogAndNewLoopResumeFromStoredLog(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $this->scope->run(static function (ExecutionScope $scope) use ($at): void {
            $store = new MemoryExecutionStore();

            $suspendingProvider = new Provider([
                new Requested(
                    id: 'cue_s1',
                    sequence: 1,
                    activityId: 'act_resume',
                    invocationId: null,
                    agentId: 'agent-test-agent',
                    at: $at,
                    effectId: 'dangerous_op',
                    kind: Kind::ShellExec,
                    summary: 'run shell command',
                    requiresApproval: true,
                ),
            ], Capabilities::empty());

            $firstLoop = new Loop(new DefaultBuilder(), $suspendingProvider, new SyncRuntimeFactory());
            $firstResult = $firstLoop($scope, new TestAgent(), new Activity\Config('act_resume', Context::new(), 1));

            self::assertSame(Activity\State::Suspended, $firstResult->state);
            self::assertSame(Outcome::WaitingForApproval, $firstResult->outcome);

            $firstLog = $firstResult->log;
            $store->saveActivity($scope, new \Phalanx\Agents\Persistence\ActivityRecord(
                id: 'act_resume',
                agentId: 'agent-test-agent',
                state: Activity\State::Running,
                startedAt: $at,
            ));
            $store->suspendActivity(
                $scope,
                'act_resume',
                $firstLog,
                new Requested(
                    id: 'cue_s1',
                    sequence: 1,
                    activityId: 'act_resume',
                    invocationId: null,
                    agentId: 'agent-test-agent',
                    at: $at,
                    effectId: 'dangerous_op',
                    kind: Kind::ShellExec,
                    summary: 'run shell command',
                    requiresApproval: true,
                ),
            );

            $suspended = $store->loadSuspended($scope, 'act_resume');
            self::assertNotNull($suspended, 'Suspended state must be persisted');
            self::assertSame('act_resume', $suspended->record->id);
            self::assertSame(Activity\State::Suspended, $suspended->record->state);

            $resumeProvider = new Provider([
                new TokenDelta('cue_r1', 2, 'act_resume', null, 'agent-test-agent', $at, 'resumed answer'),
                new TokenStop('cue_r2', 3, 'act_resume', null, 'agent-test-agent', $at, StopReason::EndOfTurn),
            ], Capabilities::empty());

            $resumeLoop = new Loop(new DefaultBuilder(), $resumeProvider, new SyncRuntimeFactory());
            $resumeResult = $resumeLoop(
                $scope,
                new TestAgent(),
                new Activity\Config('act_resume', Context::new(), 2),
                $suspended->log,
            );

            self::assertSame(Activity\State::Completed, $resumeResult->state);
            self::assertSame(Outcome::Complete, $resumeResult->outcome);
            self::assertNotEmpty($resumeResult->log->toArray(), 'Resumed loop must produce conversation records');
        });
    }
}

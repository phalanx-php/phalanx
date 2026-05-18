<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Persistence\MemoryExecutionStore;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tests\Fixtures\SyncRuntimeFactory;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Provider\Fake\Provider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PersistenceResumeTest extends TestCase
{
    #[Test]
    public function suspendedActivityPersistsLogAndNewLoopResumeFromStoredLog(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');
        $scope = new ScopeStub();
        $store = new MemoryExecutionStore();

        $suspendingProvider = new Provider([
            new Requested(
                id: 'cue_s1',
                sequence: 1,
                activityId: 'act_resume',
                invocationId: null,
                agentId: 'athena-test-agent',
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
        $store->saveActivity($scope, new \Phalanx\Athena\Persistence\ActivityRecord(
            id: 'act_resume',
            agentId: 'athena-test-agent',
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
                agentId: 'athena-test-agent',
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

        $storedLog = $suspended->log;

        $resumeProvider = new Provider([
            new TokenDelta('cue_r1', 2, 'act_resume', null, 'athena-test-agent', $at, 'resumed answer'),
            new TokenStop('cue_r2', 3, 'act_resume', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $resumeLoop = new Loop(new DefaultBuilder(), $resumeProvider, new SyncRuntimeFactory());
        $resumeResult = $resumeLoop(
            $scope,
            new TestAgent(),
            new Activity\Config('act_resume', Context::new(), 2),
            $storedLog,
        );

        self::assertSame(Activity\State::Completed, $resumeResult->state);
        self::assertSame(Outcome::Complete, $resumeResult->outcome);

        $records = $resumeResult->log->toArray();
        self::assertNotEmpty($records, 'Resumed loop must produce conversation records');
    }
}

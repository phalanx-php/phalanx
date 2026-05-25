<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Agent;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Executor;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Grant\Scope as GrantScope;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Harness\Agent\AgentExecutor;
use Phalanx\Harness\Template\Slice\PendingEffect;
use Phalanx\Harness\Tests\Support\RecordingTaskScope;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentExecutorTest extends TestCase
{
    #[Test]
    public function approveRecordsGrantWithoutReplayingSuspendedActivity(): void
    {
        $activityExecutor = new CountingActivityExecutor(State::Suspended);
        $grantStore = new RecordingGrantStore();
        $executor = new AgentExecutor(
            activity: new Activity($activityExecutor),
            scope: new RecordingTaskScope(),
            agent: self::agent(),
            config: new Config('activity-approve', Context::new()),
            grantStore: $grantStore,
        );

        foreach ($executor->send('needs approval') as $_) {
        }

        $effect = new PendingEffect(
            kind: EffectKind::FileWrite->value,
            summary: 'Write the file',
            arguments: [],
            hazardLevel: 3,
            activityId: 'activity-approve',
            effectId: 'file.write',
            agentId: 'test-agent',
            hazard: Hazard::High->value,
        );

        self::assertSame([], iterator_to_array($executor->approve($effect)));
        self::assertSame(1, $activityExecutor->calls);
        self::assertCount(1, $grantStore->remembered);
        self::assertSame('test-agent', $grantStore->remembered[0]->subject);
        self::assertSame(GrantScope::Once->value, $grantStore->remembered[0]->scope);
        self::assertSame([EffectKind::FileWrite], $grantStore->remembered[0]->allowedEffects);
        self::assertSame(Hazard::High, $grantStore->remembered[0]->hazardCeiling);
    }

    private static function agent(): Agent
    {
        return new class implements Agent {
            public string $id { get => 'test-agent'; }

            public string $name { get => 'Test Agent'; }

            public string $purpose { get => 'Exercise Theatron agent execution.'; }

            public Output $output {
                get => Output::artifact(ArtifactKind::Thesis);
            }

            public Context $context {
                get => Context::new();
            }

            public Effects $effects {
                get => Effects::allow(EffectKind::FileRead)->requireApproval(EffectKind::FileWrite);
            }

            public ProviderNeeds $provider {
                get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
            }

            public Capabilities $capabilities {
                get => Capabilities::of(Capability::Reasoning);
            }

            public TransportNeeds $transport {
                get => TransportNeeds::new()->streaming()->cancellable();
            }
        };
    }
}

final class CountingActivityExecutor implements Executor
{
    public int $calls = 0;

    public function __construct(
        private State $state,
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
    {
        $this->calls++;

        return new Result(
            activityId: $config->id,
            state: $this->state,
            outcome: Outcome::WaitingForApproval,
            log: $log ?? Log::from([]),
            invocations: 1,
            stream: Stream::from([]),
        );
    }
}

final class RecordingGrantStore implements GrantStore
{
    /** @var list<Grant> */
    public array $remembered = [];

    public function find(TaskScope $scope, string $subject, EffectKind $kind, array $arguments = []): ?Grant
    {
        return null;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
        $this->remembered[] = $grant;
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
    }
}

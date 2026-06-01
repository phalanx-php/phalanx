<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Plans;

use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkPlanTest extends TestCase
{
    #[Test]
    public function planStartsWithReadyItemsOrderedByPriorityThenInsertion(): void
    {
        $first = self::item('work_first', priority: 10);
        $second = self::item('work_second', priority: 20);
        $third = self::item('work_third', priority: 20);
        $plan = WorkPlan::start($first, $second, $third);

        self::assertSame(WorkPlanStatus::Active, $plan->status);
        self::assertSame(
            ['work_second', 'work_third', 'work_first'],
            array_map(static fn ($item): string => $item->workItem->id, $plan->readyItems()),
        );
        self::assertSame(WorkItemStatus::Pending, $plan->item('work_first')->status);
    }

    #[Test]
    public function planRejectsDuplicateUnknownAndCyclicDependencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        WorkPlan::start(self::item('work_a'), self::item('work_a'));
    }

    #[Test]
    public function planRejectsUnknownDependencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('depends on unknown item');

        WorkPlan::start(self::item('work_a', dependsOn: ['work_missing']));
    }

    #[Test]
    public function planRejectsDependencyCyclesAcrossItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dependency cycles');

        WorkPlan::start(
            self::item('work_a', dependsOn: ['work_b']),
            self::item('work_b', dependsOn: ['work_a']),
        );
    }

    #[Test]
    public function doneWorkUnlocksDependentWorkAndCompletesThePlan(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
        );

        $plan->startItem('work_a');
        $plan->fulfill(WorkResult::done('work_a', summary: 'a done'));

        self::assertSame(['work_b'], self::readyIds($plan));

        $plan->startItem('work_b');
        $plan->fulfill(WorkResult::done('work_b', summary: 'b done'));

        self::assertTrue($plan->isComplete());
        self::assertSame(WorkPlanStatus::Complete, $plan->status);
    }

    #[Test]
    public function workMustBeReadyAndRunningBeforeFulfillment(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only ready work can start.');

        $plan->startItem('work_b');
    }

    #[Test]
    public function fulfillmentRequiresRunningWork(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only running work can be fulfilled.');

        $plan->fulfill(WorkResult::done('work_a'));
    }

    #[Test]
    public function blockedFulfillmentAlsoRequiresRunningWork(): void
    {
        $plan = WorkPlan::start(self::item('work_api'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only running work can be fulfilled.');

        $plan->fulfill(WorkResult::blocked('work_api', 'missing token'));
    }

    #[Test]
    public function blockedWorkIsExternalWaitAndCanResume(): void
    {
        $plan = WorkPlan::start(self::item('work_api'));

        $plan->block('work_api', 'missing token');

        self::assertSame(WorkItemStatus::Blocked, $plan->item('work_api')->status);
        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
        self::assertSame([], $plan->readyItems());

        $plan->unblock('work_api');

        self::assertSame(WorkItemStatus::Pending, $plan->item('work_api')->status);
        self::assertSame(WorkPlanStatus::Active, $plan->status);
        self::assertSame(['work_api'], self::readyIds($plan));
    }

    #[Test]
    public function blockedFulfillmentIsAlsoAResumableExternalWait(): void
    {
        $plan = WorkPlan::start(self::item('work_api'));

        $plan->startItem('work_api');
        $plan->fulfill(WorkResult::blocked('work_api', 'missing token'));

        self::assertSame(WorkItemStatus::Blocked, $plan->item('work_api')->status);
        self::assertSame('missing token', $plan->item('work_api')->blockedReason);
        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
    }

    #[Test]
    public function planItemsAreDetachedStateSnapshots(): void
    {
        $plan = WorkPlan::start(self::item('work_api'));
        $snapshot = $plan->item('work_api');

        $plan->block('work_api', 'missing token');

        self::assertSame(WorkItemStatus::Pending, $snapshot->status);
        self::assertSame(WorkItemStatus::Blocked, $plan->item('work_api')->status);
        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
    }

    #[Test]
    public function nonCriticalFailureBlocksDependentsButIndependentWorkCanContinue(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
            self::item('work_c'),
        );

        $plan->startItem('work_a');
        $plan->fulfill(WorkResult::failed('work_a', new \RuntimeException('provider unavailable')));

        self::assertSame(WorkPlanStatus::Active, $plan->status);
        self::assertSame(['work_c'], self::readyIds($plan));
    }

    #[Test]
    public function nonCriticalFailureSuspendsWhenOnlyDependentWorkRemains(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
            self::item('work_c'),
        );

        $plan->startItem('work_a');
        $plan->fulfill(WorkResult::failed('work_a', new \RuntimeException('provider unavailable')));
        $plan->startItem('work_c');
        $plan->fulfill(WorkResult::done('work_c'));

        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
        self::assertNotContains('work_b', self::readyIds($plan));
        self::assertSame('No runnable work remains while the plan is incomplete.', $plan->statusReason);
    }

    #[Test]
    public function criticalFailureSuspendsThePlanWithoutFailedPlanState(): void
    {
        $plan = WorkPlan::start(
            self::item('work_critical', critical: true),
            self::item('work_independent'),
        );

        $plan->startItem('work_critical');
        $plan->fulfill(WorkResult::failed('work_critical', new \RuntimeException('unsafe change')));

        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
        self::assertSame([], $plan->readyItems());
        self::assertIsString($plan->statusReason);
        self::assertStringContainsString('Critical work item "work_critical" failed', $plan->statusReason);
    }

    #[Test]
    public function failedLeafWithNoRunnableWorkSuspendsInsteadOfCompleting(): void
    {
        $plan = WorkPlan::start(self::item('work_leaf'));

        $plan->startItem('work_leaf');
        $plan->fulfill(WorkResult::failed('work_leaf', new \RuntimeException('needs revision')));

        self::assertSame(WorkPlanStatus::Suspended, $plan->status);
        self::assertSame('No runnable work remains while the plan is incomplete.', $plan->statusReason);
    }

    #[Test]
    public function supersededWorkCompletesThroughReplacementPath(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
        );

        $plan->supersede('work_a', self::item('work_replacement'), 'better approach');

        self::assertSame(WorkItemStatus::Superseded, $plan->item('work_a')->status);
        self::assertNotContains('work_b', self::readyIds($plan));
        self::assertSame(['work_replacement'], self::readyIds($plan));

        $plan->startItem('work_replacement');
        $plan->fulfill(WorkResult::done('work_replacement'));

        self::assertSame(['work_b'], self::readyIds($plan));
        $plan->startItem('work_b');
        $plan->fulfill(WorkResult::done('work_b'));

        self::assertSame(WorkPlanStatus::Complete, $plan->status);
    }

    #[Test]
    public function supersededDependencyWaitsForFinalReplacementPath(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_dependent', dependsOn: ['work_a']),
        );

        $plan->supersede('work_a', self::item('work_b'), 'first revision');
        $plan->supersede('work_b', self::item('work_c'), 'second revision');

        self::assertSame(['work_c'], self::readyIds($plan));

        $plan->startItem('work_c');
        $plan->fulfill(WorkResult::done('work_c'));

        self::assertSame(['work_dependent'], self::readyIds($plan));

        $plan->startItem('work_dependent');
        $plan->fulfill(WorkResult::done('work_dependent'));

        self::assertSame(WorkPlanStatus::Complete, $plan->status);
        self::assertSame('work_b', $plan->item('work_a')->supersededBy);
        self::assertSame('work_c', $plan->item('work_b')->supersededBy);
    }

    #[Test]
    public function replacementWorkInheritsSupersededDependencies(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
        );

        $plan->supersede('work_b', self::item('work_c'), 'replace dependent work');

        self::assertSame(['work_a'], self::readyIds($plan));
        self::assertNotContains('work_c', self::readyIds($plan));

        $plan->startItem('work_a');
        $plan->fulfill(WorkResult::done('work_a'));

        self::assertSame(['work_c'], self::readyIds($plan));
    }

    #[Test]
    public function supersessionCanReviseCriticalFailureAndResume(): void
    {
        $plan = WorkPlan::start(self::item('work_critical', critical: true));

        $plan->startItem('work_critical');
        $plan->fulfill(WorkResult::failed('work_critical', new \RuntimeException('bad path')));
        $plan->supersede('work_critical', self::item('work_replacement'), 'safer path');

        self::assertSame(WorkPlanStatus::Active, $plan->status);
        self::assertSame(['work_replacement'], self::readyIds($plan));
    }

    #[Test]
    public function supersessionRejectsBrokenReplacementChains(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot depend on the work it supersedes');

        $plan->supersede('work_a', self::item('work_b', dependsOn: ['work_a']), 'cyclic replacement');
    }

    #[Test]
    public function supersessionRejectsEffectiveDependencyCycles(): void
    {
        $plan = WorkPlan::start(
            self::item('work_a'),
            self::item('work_b', dependsOn: ['work_a']),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dependency cycles');

        $plan->supersede('work_a', self::item('work_c', dependsOn: ['work_b']), 'cyclic revision');
    }

    #[Test]
    public function abortedPlanIsTerminal(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));

        $plan->abort('user stopped the round');

        self::assertSame(WorkPlanStatus::Aborted, $plan->status);
        self::assertSame([], $plan->readyItems());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Complete or aborted work plans cannot be changed');

        $plan->append(self::item('work_b'));
    }

    #[Test]
    public function abortedPlanRejectsAllStateMutations(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));
        $plan->abort('user stopped the round');

        foreach (
            [
            static fn () => $plan->startItem('work_a'),
            static fn () => $plan->fulfill(WorkResult::done('work_a')),
            static fn () => $plan->block('work_a', 'waiting'),
            static fn () => $plan->unblock('work_a'),
            static fn () => $plan->supersede('work_a', self::item('work_b'), 'revision'),
            static fn () => $plan->abort('again'),
            ] as $mutation
        ) {
            try {
                $mutation();
                self::fail('Expected aborted plan mutation to fail.');
            } catch (\LogicException $error) {
                self::assertSame('Complete or aborted work plans cannot be changed.', $error->getMessage());
            }
        }
    }

    #[Test]
    public function completedPlanIsTerminal(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));
        $plan->startItem('work_a');
        $plan->fulfill(WorkResult::done('work_a'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Complete or aborted work plans cannot be changed');

        $plan->append(self::item('work_b'));
    }

    #[Test]
    public function canonicalPlanIncludesStateReasonsAndReplacementLinks(): void
    {
        $plan = WorkPlan::start(self::item('work_a'));
        $plan->supersede('work_a', self::item('work_b'), 'review requested a smaller patch');

        $canonical = $plan->toCanonical();

        self::assertSame(WorkPlanStatus::Active, $canonical['status']);
        self::assertSame(WorkItemStatus::Superseded, $canonical['items'][0]['status']);
        self::assertSame('work_b', $canonical['items'][0]['superseded_by']);
        self::assertSame('review requested a smaller patch', $canonical['items'][0]['superseded_reason']);
        self::assertArrayNotHasKey('ready', $canonical);
        self::assertArrayNotHasKey('ready', $canonical['items'][0]);
    }

    #[Test]
    public function canonicalPlanIncludesBlockingAndResolutionState(): void
    {
        $plan = WorkPlan::start(self::item('work_api'));
        $resolvedBy = Envelope::prompt('token ready');

        $plan->block('work_api', 'missing token');
        $blocked = $plan->toCanonical();

        self::assertSame(WorkPlanStatus::Suspended, $blocked['status']);
        self::assertSame('missing token', $blocked['items'][0]['blocked_reason']);
        self::assertNull($blocked['items'][0]['resolved_by']);

        $plan->unblock('work_api', $resolvedBy);
        $unblocked = $plan->toCanonical();

        self::assertSame(WorkPlanStatus::Active, $unblocked['status']);
        self::assertSame(WorkItemStatus::Pending, $unblocked['items'][0]['status']);
        self::assertNull($unblocked['items'][0]['blocked_reason']);
        self::assertSame('token ready', $unblocked['items'][0]['resolved_by']['payload']);
        self::assertSame('work_api', $unblocked['items'][0]['work_item']['id']);
    }

    /**
     * @param list<string> $dependsOn
     */
    private static function item(
        string $id,
        array $dependsOn = [],
        int $priority = 0,
        bool $critical = false,
    ): WorkItem {
        return new WorkItem(
            activity: Activity::Thinking,
            prompt: sprintf('Do %s', $id),
            dependsOn: $dependsOn,
            priority: $priority,
            critical: $critical,
            id: $id,
        );
    }

    /**
     * @return list<string>
     */
    private static function readyIds(WorkPlan $plan): array
    {
        return array_map(static fn ($item): string => $item->workItem->id, $plan->readyItems());
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Plans;

use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemTest extends TestCase
{
    #[Test]
    public function workItemCarriesDAGAndRoutingFields(): void
    {
        $item = new WorkItem(
            activity: Activity::Exploring,
            prompt: 'Find the AgentHarness contracts',
            dependsOn: ['work_a', 'work_a', 'work_b'],
            tags: ['codebase', 'codebase', 'agent-harness'],
            preferredParticipant: Address::agent('explorer'),
            priority: 25,
            critical: true,
            id: 'work_c',
        );

        self::assertSame('work_c', $item->id);
        self::assertSame(['work_a', 'work_b'], $item->dependsOn);
        self::assertSame(['codebase', 'agent-harness'], $item->tags);
        self::assertTrue($item->critical);
        self::assertSame('agent:explorer', $item->preferredParticipant?->identity);
        self::assertSame([
            'id' => 'work_c',
            'activity' => Activity::Exploring,
            'prompt' => 'Find the AgentHarness contracts',
            'depends_on' => ['work_a', 'work_b'],
            'tags' => ['codebase', 'agent-harness'],
            'preferred_participant' => [
                'identity' => 'agent:explorer',
                'role' => 'agent',
            ],
            'priority' => 25,
            'critical' => true,
        ], $item->toCanonical());
    }

    #[Test]
    public function workItemReadinessIsComputedFromCompletedDependencies(): void
    {
        $item = new WorkItem(
            activity: Activity::Testing,
            prompt: 'Run AgentHarness tests',
            dependsOn: ['work_edit'],
            id: 'work_test',
        );

        self::assertTrue($item->isBlockedBy([]));
        self::assertSame(['work_edit'], $item->missingDependencies([]));
        self::assertFalse($item->isBlockedBy(['work_edit']));
        self::assertSame([], $item->missingDependencies(['work_edit']));
    }

    #[Test]
    public function generatedWorkItemIdIsStableAfterConstruction(): void
    {
        $item = new WorkItem(
            activity: Activity::Thinking,
            prompt: 'Plan next step',
        );

        self::assertStringStartsWith('work_', $item->id);
        self::assertSame($item->id, $item->toCanonical()['id']);
    }

    #[Test]
    public function workItemRejectsSelfDependency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot depend on itself');

        new WorkItem(
            activity: Activity::Testing,
            prompt: 'Run itself',
            dependsOn: ['work_self'],
            id: 'work_self',
        );
    }

    #[Test]
    public function workItemRejectsEmptyExplicitId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('id cannot be empty');

        new WorkItem(
            activity: Activity::Testing,
            prompt: 'Run AgentHarness tests',
            id: '   ',
        );
    }

    #[Test]
    public function workItemRejectsEmptyPrompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt cannot be empty');

        new WorkItem(
            activity: Activity::Testing,
            prompt: '   ',
        );
    }
}

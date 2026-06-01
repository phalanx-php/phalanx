<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Screens;

use DateTimeImmutable;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Messages\MessageKind;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Projection\CollabProjector;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\Screens\WorkspaceScreen;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\DevToolsSlice;
use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Core\ScreenContext;
use Phalanx\Theatron\Tui\Navigation\Navigator;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tui\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tui\Tdom\Element\GridElement;
use Phalanx\Theatron\Tui\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tui\Tdom\Element\ScrollElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkspaceScreenTest extends TestCase
{
    #[Test]
    public function rendersProjectionBackedWorkspacePanels(): void
    {
        $store = self::store();

        $rendered = (new WorkspaceScreen($store))($this->screenContext());

        self::assertInstanceOf(ColumnElement::class, $rendered);
        self::assertInstanceOf(GridElement::class, $rendered->children[0]);
        self::assertInstanceOf(GridElement::class, $rendered->children[1]);

        $chat = $rendered->children[0]->children[0];
        $plan = $rendered->children[0]->children[1];
        $runtime = $rendered->children[1]->children[0];
        $devTools = $rendered->children[1]->children[1];

        self::assertInstanceOf(PanelElement::class, $chat);
        self::assertInstanceOf(PanelElement::class, $plan);
        self::assertInstanceOf(PanelElement::class, $runtime);
        self::assertInstanceOf(PanelElement::class, $devTools);

        self::assertStringContainsString('prompt Review projection', self::scrollContent($chat));
        self::assertStringContainsString('done work_projection - Review projection', self::scrollContent($plan));
        self::assertStringContainsString('participants: primary, reviewer', self::scrollContent($runtime));
        self::assertStringContainsString('event: evt_done', self::scrollContent($devTools));
    }

    private static function store(): CollabStore
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $time = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $workItem = new WorkItem(Activity::Testing, 'Review projection', id: 'work_projection');

        $projector->project(CollabEvent::record(
            EventKind::WorkReceived,
            envelope: Envelope::make(
                from: Address::user(),
                to: Address::agent('primary'),
                kind: MessageKind::Prompt,
                payload: 'Review projection',
                id: 'env_prompt',
            ),
            workItem: $workItem,
            context: [
                'runtime_session_id' => 'session_a',
                'runtime_health' => 'ready',
                'context_pressure' => 14,
                'context_active_focus' => 'work_projection',
                'participants' => ['primary', 'reviewer'],
            ],
            occurredAt: $time,
            id: 'evt_received',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            occurredAt: $time,
            id: 'evt_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_projection', summary: 'Projection reviewed.'),
            occurredAt: $time,
            id: 'evt_done',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkReviewed,
            reviewVerdict: ReviewVerdict::approve(),
            occurredAt: $time,
            id: 'evt_review',
        ), $store);

        $store->devTools = new DevToolsSlice(
            selectedEventId: 'evt_done',
            filters: ['work_completed'],
        );

        return $store;
    }

    private static function scrollContent(PanelElement $panel): string
    {
        self::assertInstanceOf(ScrollElement::class, $panel->child);

        return $panel->child->content;
    }

    private function screenContext(): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);

        return new ScreenContext(
            scope: $scope,
            theme: Theme::default(),
            navigator: $this->createStub(Navigator::class),
            mountSystem: new MountSystem($scope, $scope),
            height: 24,
        );
    }
}

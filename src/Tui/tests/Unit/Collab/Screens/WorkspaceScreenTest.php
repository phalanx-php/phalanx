<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Screens;

use DateTimeImmutable;
use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Boundaries\InletQueue;
use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Messages\MessageKind;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Projection\Projector;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;
use Phalanx\Tui\Collab\Screens\WorkspaceScreen;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\DevToolsSlice;
use Phalanx\Tui\Core\AcceptsInput;
use Phalanx\Tui\Core\MountSystem;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Inputs\Key;
use Phalanx\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Navigation\Navigator;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tdom\Element\InputElement;
use Phalanx\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tdom\Element\ScrollElement;
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
        $input = $rendered->children[2];

        self::assertInstanceOf(PanelElement::class, $chat);
        self::assertInstanceOf(PanelElement::class, $plan);
        self::assertInstanceOf(PanelElement::class, $runtime);
        self::assertInstanceOf(PanelElement::class, $devTools);
        self::assertInstanceOf(PanelElement::class, $input);
        self::assertInstanceOf(InputElement::class, $input->child);

        self::assertStringContainsString('prompt Review projection', self::scrollContent($chat));
        self::assertStringContainsString('done work_projection - Review projection', self::scrollContent($plan));
        self::assertStringContainsString('participants: primary, reviewer', self::scrollContent($runtime));
        self::assertStringContainsString('event: evt_done', self::scrollContent($devTools));
    }

    #[Test]
    public function rendersEmptyWorkspaceFallbacks(): void
    {
        $rendered = (new WorkspaceScreen(new Store()))($this->screenContext());

        self::assertInstanceOf(ColumnElement::class, $rendered);
        self::assertInstanceOf(GridElement::class, $rendered->children[0]);
        self::assertInstanceOf(GridElement::class, $rendered->children[1]);

        $chat = $rendered->children[0]->children[0];
        $plan = $rendered->children[0]->children[1];
        $runtime = $rendered->children[1]->children[0];
        $devTools = $rendered->children[1]->children[1];
        $input = $rendered->children[2];

        self::assertInstanceOf(PanelElement::class, $chat);
        self::assertInstanceOf(PanelElement::class, $plan);
        self::assertInstanceOf(PanelElement::class, $runtime);
        self::assertInstanceOf(PanelElement::class, $devTools);
        self::assertInstanceOf(PanelElement::class, $input);
        self::assertInstanceOf(InputElement::class, $input->child);
        self::assertStringContainsString('No timeline entries.', self::scrollContent($chat));
        self::assertStringContainsString('No work planned.', self::scrollContent($plan));
        self::assertStringContainsString('session: none', self::scrollContent($runtime));
        self::assertStringContainsString('event: none', self::scrollContent($devTools));
    }

    #[Test]
    public function inputFocusableSubmitsThroughTheReceivePath(): void
    {
        $queue = new InletQueue();
        $screen = new WorkspaceScreen(new Store(), new InputPromptSubmitter($queue));
        $focusables = $screen->focusables();

        self::assertCount(1, $focusables);
        self::assertSame('input', $focusables[0][0]);

        $input = $focusables[0][1];
        self::assertInstanceOf(AcceptsInput::class, $input);

        $input->handleInput(new KeyEvent('s'));
        $input->handleInput(new KeyEvent('h'));
        $input->handleInput(new KeyEvent('i'));
        $input->handleInput(new KeyEvent('p'));
        $input->handleInput(new KeyEvent(Key::Enter));

        $messages = $queue->drain();

        self::assertCount(1, $messages);
        self::assertSame('ship', $messages[0]->envelope->payload);
    }

    #[Test]
    public function inputPanelRendersComposerCursorState(): void
    {
        $screen = new WorkspaceScreen(new Store(), new InputPromptSubmitter(new InletQueue()));
        $focusables = $screen->focusables();
        $input = $focusables[0][1];
        self::assertInstanceOf(AcceptsInput::class, $input);

        $input->handleInput(new KeyEvent('a'));
        $input->handleInput(new KeyEvent('b'));
        $input->handleInput(new KeyEvent(Key::Left));

        $rendered = $screen($this->screenContext());
        self::assertInstanceOf(ColumnElement::class, $rendered);

        $panel = $rendered->children[2];

        self::assertInstanceOf(PanelElement::class, $panel);
        self::assertInstanceOf(InputElement::class, $panel->child);
        self::assertSame('ab', $panel->child->value);
        self::assertSame(1, $panel->child->cursor);
    }

    private static function store(): Store
    {
        $store = new Store();
        $projector = new Projector();
        $time = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $workItem = new WorkItem(Activity::Testing, 'Review projection', id: 'work_projection');

        $projector->project(Event::record(
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
        $projector->project(Event::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            occurredAt: $time,
            id: 'evt_started',
        ), $store);
        $projector->project(Event::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_projection', summary: 'Projection reviewed.'),
            occurredAt: $time,
            id: 'evt_done',
        ), $store);
        $projector->project(Event::record(
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

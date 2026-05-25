<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Component;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Mountable;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\SignalRegistry;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\mount;
use function Phalanx\Theatron\Ui\row;

final class MountSystemTest extends TestCase
{
    #[Test]
    public function mountReturnsMountedComponent(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(SimpleTestComponent::class);

        self::assertInstanceOf(MountedComponent::class, $mounted);
    }

    #[Test]
    public function mountPassesRuntimeParams(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(ParamTestComponent::class, label: 'Apollo');

        $ctx = new RenderContext(
            $this->createStub(\Phalanx\Scope\Scope::class),
            Theme::default(),
            $system,
        );

        $result = $mounted->render($ctx);
        self::assertInstanceOf(TextElement::class, $result);
        self::assertSame('Apollo', $result->content);
    }

    #[Test]
    public function mountCreatesComponentWithSignalDefaults(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(SignalTestComponent::class);

        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function mountPassesSharedSignal(): void
    {
        $shared = new Signal('from-parent');
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(BorrowingComponent::class, input: $shared);

        $ctx = new RenderContext(
            $this->createStub(\Phalanx\Scope\Scope::class),
            Theme::default(),
            $system,
        );

        $mounted->render($ctx);
        $mounted->dispose();

        self::assertFalse($shared->isDisposed, 'Shared signal survives child dispose');
    }

    #[Test]
    public function disposeAllCleansUpAllMounted(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $a = $system->mountComponent(SimpleTestComponent::class);
        $b = $system->mountComponent(SimpleTestComponent::class);

        self::assertFalse($a->isDisposed);
        self::assertFalse($b->isDisposed);

        $system->disposeAll();

        self::assertTrue($a->isDisposed);
        self::assertTrue($b->isDisposed);
    }

    #[Test]
    public function disposeAllIsIdempotent(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(SimpleTestComponent::class);

        $system->disposeAll();
        $system->disposeAll();

        self::assertTrue($mounted->isDisposed);
    }

    #[Test]
    public function mountCallsOnMountWithTaskScope(): void
    {
        $tracker = new \stdClass();
        $tracker->mounted = false;
        $tracker->scope = null;

        $taskScope = $this->createStub(TaskScope::class);
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class), $taskScope);

        $system->mountComponent(MountableTestComponent::class, tracker: $tracker);

        self::assertTrue($tracker->mounted);
        self::assertSame($taskScope, $tracker->scope);
    }

    #[Test]
    public function mountDerivesTaskScopeFromScopeArgument(): void
    {
        $tracker = new \stdClass();
        $tracker->mounted = false;
        $tracker->scope = null;

        $taskScope = $this->createStub(TaskScope::class);
        $system = new MountSystem($taskScope, registry: new SignalRegistry());

        $system->mountComponent(MountableTestComponent::class, tracker: $tracker);

        self::assertTrue($tracker->mounted);
        self::assertSame($taskScope, $tracker->scope);
    }

    #[Test]
    public function mountSkipsOnMountWithoutTaskScope(): void
    {
        $tracker = new \stdClass();
        $tracker->mounted = false;
        $tracker->scope = null;
        $tracker->unmounted = false;

        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $system->mountComponent(MountableTestComponent::class, tracker: $tracker);

        self::assertFalse($tracker->mounted, 'onMount must not be called without TaskScope');

        $system->disposeAll();

        self::assertFalse($tracker->unmounted, 'onUnmount must not run when onMount never ran');
    }

    #[Test]
    public function taskScopeDisposeRegistrationIsScopedToMountSystem(): void
    {
        $taskScope = $this->createMock(TaskScope::class);
        $taskScope->expects(self::once())->method('onDispose');
        $system = new MountSystem($taskScope);

        $system->mountComponent(SimpleTestComponent::class);
        $system->mountComponent(SimpleTestComponent::class);
        $system->mountComponent(SimpleTestComponent::class);

        self::assertCount(3, $system->mounted());
    }

    #[Test]
    public function disposeCallsOnUnmount(): void
    {
        $tracker = new \stdClass();
        $tracker->mounted = false;
        $tracker->scope = null;
        $tracker->unmounted = false;

        $taskScope = $this->createStub(TaskScope::class);
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class), $taskScope);

        $mounted = $system->mountComponent(MountableTestComponent::class, tracker: $tracker);

        self::assertTrue($tracker->mounted);
        self::assertFalse($tracker->unmounted);

        $mounted->dispose();

        self::assertTrue($tracker->unmounted, 'onUnmount must be called during dispose');
    }

    #[Test]
    public function mountAfterDisposeAllWorks(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $a = $system->mountComponent(SimpleTestComponent::class);
        $system->disposeAll();
        self::assertTrue($a->isDisposed);

        $b = $system->mountComponent(SimpleTestComponent::class);
        self::assertFalse($b->isDisposed);
    }

    #[Test]
    public function mountRejectsPositionalProps(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Component props must be passed as named arguments.');

        $system->mountComponent(ParamTestComponent::class, 'Apollo');
    }

    #[Test]
    public function mountedReturnsAllMountedComponents(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        self::assertCount(0, $system->mounted());

        $system->mountComponent(SimpleTestComponent::class);
        $system->mountComponent(SimpleTestComponent::class);

        self::assertCount(2, $system->mounted());
    }

    #[Test]
    public function mountedReturnsCopyNotReference(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $system->mountComponent(SimpleTestComponent::class);

        $copy = $system->mounted();
        $copy[] = $system->mountComponent(SimpleTestComponent::class);

        self::assertCount(2, $system->mounted(), 'Mutating returned array must not affect internal list');
    }

    #[Test]
    public function disposeAllClearsMountedList(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $system->mountComponent(SimpleTestComponent::class);
        $system->mountComponent(SimpleTestComponent::class);

        self::assertCount(2, $system->mounted());

        $system->disposeAll();

        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function mountThreadsRegistryToScanner(): void
    {
        $registry = new SignalRegistry();
        $system = new MountSystem(
            $this->createStub(\Phalanx\Scope\Scope::class),
            registry: $registry,
        );

        $system->mountComponent(SignalHoldingComponent::class);

        $snapshot = $registry->snapshot();
        self::assertCount(1, $snapshot);
        self::assertStringEndsWith('::counter', $snapshot[0]->label);
    }

    #[Test]
    public function mountWithoutRegistrySkipsRegistration(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));

        $mounted = $system->mountComponent(SignalHoldingComponent::class);

        self::assertSame(1, $mounted->signalCount);
    }

    #[Test]
    public function borrowedSignalIsNotRegistered(): void
    {
        $registry = new SignalRegistry();
        $shared = new Signal('parent-owned');
        $system = new MountSystem(
            $this->createStub(\Phalanx\Scope\Scope::class),
            registry: $registry,
        );

        $system->mountComponent(BorrowedOnlyComponent::class, input: $shared);

        self::assertSame(0, $registry->count());
    }

    #[Test]
    public function provideSuppliesAmbientDependency(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $service = new ProvidedService('olympus');
        $system->provide(ProvidedService::class, $service);

        $mounted = $system->mountComponent(ServiceConsumerComponent::class);

        $ctx = new RenderContext(
            $this->createStub(\Phalanx\Scope\Scope::class),
            Theme::default(),
            $system,
        );

        $result = $mounted->render($ctx);
        self::assertInstanceOf(TextElement::class, $result);
        self::assertSame('olympus', $result->content);
    }

    #[Test]
    public function renderSlotReusesMountedChildForStableFqcnAndProps(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new SlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];
        $first->render($ctx);

        $model->parentSignal->set('parent changed');
        $parent->render($ctx);

        self::assertSame($first, $system->mounted()[0]);
        self::assertCount(1, $system->mounted());
        self::assertFalse($first->isDisposed);
    }

    #[Test]
    public function childRenderDependencyDoesNotDirtyParentFrame(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new SlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];
        $child->render($ctx);
        self::assertFalse($parent->isDirty);
        self::assertFalse($child->isDirty);

        $model->childSignal->set('child changed');

        self::assertFalse($parent->isDirty);
        self::assertTrue($child->isDirty);
    }

    #[Test]
    public function parentRenderDependencyDoesNotDirtyChildFrame(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new SlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];
        $child->render($ctx);
        self::assertFalse($parent->isDirty);
        self::assertFalse($child->isDirty);

        $model->parentSignal->set('parent changed');

        self::assertTrue($parent->isDirty);
        self::assertFalse($child->isDirty);
    }

    #[Test]
    public function changedSlotPropsRemountAndDisposePreviousChild(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->label = new Signal('first');
        $parent = $this->mountParent(new LabelSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];

        $model->label->set('second');
        $parent->render($ctx);
        $second = $system->mounted()[0];

        self::assertNotSame($first, $second);
        self::assertTrue($first->isDisposed);
        self::assertFalse($second->isDisposed);
        self::assertCount(1, $system->mounted());
    }

    #[Test]
    public function failedReplacementActivationPreservesPreviousSlot(): void
    {
        $taskScope = $this->createStub(TaskScope::class);
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class), $taskScope);
        $tracker = new \stdClass();
        $tracker->mounted = 0;
        $tracker->unmounted = 0;
        $model = new \stdClass();
        $model->label = new Signal('first');
        $model->fail = false;
        $model->tracker = $tracker;
        $parent = $this->mountParent(new ActivationSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];

        $model->label->set('second');
        $model->fail = true;

        try {
            $parent->render($ctx);
            self::fail('Expected failed replacement activation.');
        } catch (\RuntimeException $e) {
            self::assertSame('replacement mount failed', $e->getMessage());
        }

        self::assertFalse($first->isDisposed);
        self::assertSame($first, $system->mounted()[0]);
        self::assertCount(1, $system->mounted());
        self::assertSame(1, $tracker->mounted);
        self::assertSame(0, $tracker->unmounted);
    }

    #[Test]
    public function unusedRenderSlotsAreDisposedAfterSuccessfulParentRender(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->show = new Signal(true);
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new ConditionalSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];

        $model->show->set(false);
        $parent->render($ctx);

        self::assertTrue($child->isDisposed);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function disposingParentDisposesOwnedChildSlots(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new SlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];
        $child->render($ctx);

        $parent->dispose();

        self::assertTrue($child->isDisposed);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function replacingNestedParentDisposesDescendantSlots(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->label = new Signal('first');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new NestedParentSlotComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $nestedParent = $system->mounted()[0];
        $nestedParent->render($ctx);
        $descendant = $system->mounted()[1];

        $model->label->set('second');
        $parent->render($ctx);

        self::assertTrue($nestedParent->isDisposed);
        self::assertTrue($descendant->isDisposed);
        self::assertCount(1, $system->mounted());
    }

    #[Test]
    public function failedParentRenderRollsBackReplacementSlot(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->label = new Signal('first');
        $model->throw = false;
        $parent = $this->mountParent(new FailingAfterReplacementSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];

        $model->label->set('second');
        $model->throw = true;

        try {
            $parent->render($ctx);
            self::fail('Expected failed parent render.');
        } catch (\RuntimeException $e) {
            self::assertSame('parent failed after replacement', $e->getMessage());
        }

        self::assertFalse($first->isDisposed);
        self::assertSame($first, $system->mounted()[0]);
        self::assertCount(1, $system->mounted());
    }

    #[Test]
    public function failedParentRenderRollsBackNestedCommittedSlots(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->childSignal = new Signal('child');
        $model->nestedChildRenderCount = 0;
        $model->mountedCountBeforeThrow = 0;
        $parent = $this->mountParent(new FailingAfterNestedChildRenderParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        try {
            $parent->render($ctx);
            self::fail('Expected failed parent render.');
        } catch (\RuntimeException $e) {
            self::assertSame('parent failed after nested render', $e->getMessage());
        }

        self::assertSame(1, $model->nestedChildRenderCount);
        self::assertSame(2, $model->mountedCountBeforeThrow);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function failedParentRenderPreservesPreviousSuccessfulSlots(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->throw = false;
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new FailingBeforeSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];

        $model->throw = true;

        try {
            $parent->render($ctx);
            self::fail('Expected failed parent render.');
        } catch (\RuntimeException $e) {
            self::assertSame('parent failed', $e->getMessage());
        }

        self::assertFalse($child->isDisposed);
        self::assertSame($child, $system->mounted()[0]);
        self::assertCount(1, $system->mounted());
    }

    #[Test]
    public function disposingMountedScreenDisposesOwnedSlots(): void
    {
        $scope = $this->createStub(TaskScope::class);
        $system = new MountSystem($scope);
        $model = new \stdClass();
        $model->childSignal = new Signal('child');
        $screen = $system->mountScreen(SlotOwnerScreen::class, model: $model);

        $screen->render($this->screenContext($system, $scope));
        $child = $system->mounted()[0];
        $child->render($this->renderContext($system));

        $screen->dispose();

        self::assertTrue($child->isDisposed);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function changingProvidedDependencyRemountsStableSlots(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $parent = $this->mountParent(new AmbientServiceSlotParentComponent(), $system);
        $ctx = $this->renderContext($system);

        $system->provide(ProvidedService::class, new ProvidedService('first'));
        $parent->render($ctx);
        $first = $system->mounted()[0];
        $firstResult = $first->render($ctx);

        $system->provide(ProvidedService::class, new ProvidedService('second'));
        $parent->render($ctx);
        $second = $system->mounted()[0];
        $secondResult = $second->render($ctx);

        self::assertNotSame($first, $second);
        self::assertTrue($first->isDisposed);
        self::assertInstanceOf(TextElement::class, $firstResult);
        self::assertInstanceOf(TextElement::class, $secondResult);
        self::assertSame('first', $firstResult->content);
        self::assertSame('second', $secondResult->content);
    }

    #[Test]
    public function namedPropsBeatProvidedAndScopeServices(): void
    {
        $scopeService = new ProvidedService('scope');
        $providedService = new ProvidedService('provided');
        $namedService = new ProvidedService('named');
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $scope->method('service')->willReturn($scopeService);
        $system = new MountSystem($scope);
        $system->provide(ProvidedService::class, $providedService);

        $mounted = $system->mountComponent(ServiceConsumerComponent::class, service: $namedService);
        $result = $mounted->render($this->renderContext($system));

        self::assertInstanceOf(TextElement::class, $result);
        self::assertSame('named', $result->content);
    }

    #[Test]
    public function providedDependenciesBeatScopeServices(): void
    {
        $scopeService = new ProvidedService('scope');
        $providedService = new ProvidedService('provided');
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $scope->method('service')->willReturn($scopeService);
        $system = new MountSystem($scope);
        $system->provide(ProvidedService::class, $providedService);

        $mounted = $system->mountComponent(ServiceConsumerComponent::class);
        $result = $mounted->render($this->renderContext($system));

        self::assertInstanceOf(TextElement::class, $result);
        self::assertSame('provided', $result->content);
    }

    #[Test]
    public function scopeServicesResolveWhenNoPropOrProvidedDependencyExists(): void
    {
        $scopeService = new ProvidedService('scope');
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $scope->method('service')->willReturn($scopeService);
        $system = new MountSystem($scope);

        $mounted = $system->mountComponent(ServiceConsumerComponent::class);
        $result = $mounted->render($this->renderContext($system));

        self::assertInstanceOf(TextElement::class, $result);
        self::assertSame('scope', $result->content);
    }

    #[Test]
    public function functionMountReusesChildForStableParentRender(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new FunctionSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];
        $first->render($ctx);

        $model->parentSignal->set('parent changed');
        $parent->render($ctx);

        self::assertSame($first, $system->mounted()[0]);
        self::assertCount(1, $system->mounted());
        self::assertFalse($first->isDisposed);
    }

    #[Test]
    public function functionMountChangedPropsRemountChild(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->label = new Signal('first');
        $parent = $this->mountParent(new FunctionLabelSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $first = $system->mounted()[0];

        $model->label->set('second');
        $parent->render($ctx);
        $second = $system->mounted()[0];

        self::assertNotSame($first, $second);
        self::assertTrue($first->isDisposed);
        self::assertFalse($second->isDisposed);
        self::assertCount(1, $system->mounted());
    }

    #[Test]
    public function functionMountChildSignalDirtiesOnlyChild(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new FunctionSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];
        $child->render($ctx);
        self::assertFalse($parent->isDirty);
        self::assertFalse($child->isDirty);

        $model->childSignal->set('child changed');

        self::assertFalse($parent->isDirty);
        self::assertTrue($child->isDirty);
    }

    #[Test]
    public function functionMountParentSignalDirtiesOnlyParent(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->parentSignal = new Signal('parent');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new FunctionSlotParentComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $child = $system->mounted()[0];
        $child->render($ctx);
        self::assertFalse($parent->isDirty);
        self::assertFalse($child->isDirty);

        $model->parentSignal->set('parent changed');

        self::assertTrue($parent->isDirty);
        self::assertFalse($child->isDirty);
    }

    #[Test]
    public function functionMountDirtyDescendantIsVisibleFromOwner(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $model = new \stdClass();
        $model->label = new Signal('nested');
        $model->childSignal = new Signal('child');
        $parent = $this->mountParent(new NestedParentSlotComponent($model), $system);
        $ctx = $this->renderContext($system);

        $parent->render($ctx);
        $nestedParent = $system->mounted()[0];
        $nestedParent->render($ctx);
        $descendant = $system->mounted()[1];
        $descendant->render($ctx);
        self::assertFalse($system->hasDirtyOwnedSlots($parent));

        $model->childSignal->set('child changed');

        self::assertTrue($system->hasDirtyOwnedSlots($parent));
        self::assertTrue($system->hasDirtyOwnedSlots($nestedParent));
    }

    #[Test]
    public function resolvePreservesRowMetadataWhileSubstitutingMounts(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $style = Style::empty();
        $row = row(mount(SimpleTestComponent::class))->styled($style);

        $resolved = $system->resolve($row);

        self::assertInstanceOf(RowElement::class, $resolved);
        self::assertSame($style, $resolved->style);
        self::assertInstanceOf(MountedComponent::class, $resolved->children[0]);
    }

    #[Test]
    public function resolvePreservesGridMetadataWhileSubstitutingMounts(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $style = Style::empty();
        $columns = [Size::fixed(4)];
        $grid = new GridElement($columns, [mount(SimpleTestComponent::class)], $style);

        $resolved = $system->resolve($grid);

        self::assertInstanceOf(GridElement::class, $resolved);
        self::assertSame($style, $resolved->style);
        self::assertSame($columns, $resolved->columns);
        self::assertInstanceOf(MountedComponent::class, $resolved->children[0]);
    }

    #[Test]
    public function resolvePreservesStatusLineMetadataWhileSubstitutingMounts(): void
    {
        $system = new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
        $style = Style::empty();
        $status = new StatusLineElement([mount(SimpleTestComponent::class)], $style);

        $resolved = $system->resolve($status);

        self::assertInstanceOf(StatusLineElement::class, $resolved);
        self::assertSame($style, $resolved->style);
        self::assertInstanceOf(MountedComponent::class, $resolved->sections[0]);
    }

    private function renderContext(MountSystem $system): RenderContext
    {
        return new RenderContext(
            $this->createStub(\Phalanx\Scope\Scope::class),
            Theme::default(),
            $system,
        );
    }

    private function screenContext(MountSystem $system, TaskScope $scope): ScreenContext
    {
        return new ScreenContext(
            $scope,
            Theme::default(),
            $this->createStub(Navigator::class),
            $system,
        );
    }

    private function mountParent(Component $component, MountSystem $system): MountedComponent
    {
        $dirty = new \Phalanx\Theatron\Reactive\DirtyBatch();
        $scanResult = \Phalanx\Theatron\Component\SignalScanner::scan($component, $dirty);

        return new MountedComponent($component, $dirty, $scanResult);
    }
}

final class SimpleTestComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('simple');
    }
}

final class ParamTestComponent implements Component
{
    public function __construct(
        private(set) string $label = 'default',
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->label);
    }
}

final class SignalTestComponent implements Component
{
    public function __construct(
        private(set) Signal $count = new Signal(0),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text((string) $this->count->get());
    }
}

final class BorrowingComponent implements Component
{
    public function __construct(
        private(set) Signal $input,
        private(set) Signal $local = new Signal(''),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text((string) $this->input->get());
    }
}

final class MountableTestComponent implements Component, Mountable
{
    public function __construct(
        private \stdClass $tracker,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('mountable');
    }

    public function onMount(TaskScope $scope): void
    {
        $this->tracker->mounted = true;
        $this->tracker->scope = $scope;
    }

    public function onUnmount(): void
    {
        $this->tracker->unmounted = true;
    }
}

final class ProvidedService
{
    public function __construct(
        private(set) string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }
}

final class ServiceConsumerComponent implements Component
{
    public function __construct(
        private(set) ProvidedService $service,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->service->name());
    }
}

final class SignalHoldingComponent implements Component
{
    public function __construct(
        private(set) Signal $counter = new Signal(0),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text((string) $this->counter->get());
    }
}

final class BorrowedOnlyComponent implements Component
{
    public function __construct(
        private(set) Signal $input,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text((string) $this->input->get());
    }
}

final class SlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $this->model->parentSignal->get();

        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class SlotChildComponent implements Component
{
    public function __construct(
        private Signal $input,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text((string) $this->input->get());
    }
}

final class LabelSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(LabelSlotChildComponent::class, label: (string) $this->model->label->get()),
        );
    }
}

final class LabelSlotChildComponent implements Component
{
    public function __construct(
        private string $label,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->label);
    }
}

final class ActivationSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $component = $this->model->fail
            ? FailingActivationSlotChildComponent::class
            : TrackedActivationSlotChildComponent::class;

        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(
                $component,
                label: (string) $this->model->label->get(),
                tracker: $this->model->tracker,
            ),
        );
    }
}

final class TrackedActivationSlotChildComponent implements Component, Mountable
{
    public function __construct(
        private string $label,
        private \stdClass $tracker,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->label);
    }

    public function onMount(TaskScope $scope): void
    {
        $this->tracker->mounted++;
    }

    public function onUnmount(): void
    {
        $this->tracker->unmounted++;
    }
}

final class FailingActivationSlotChildComponent implements Component, Mountable
{
    public function __construct(
        private string $label,
        private \stdClass $tracker,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->label);
    }

    public function onMount(TaskScope $scope): void
    {
        throw new \RuntimeException('replacement mount failed');
    }

    public function onUnmount(): void
    {
        $this->tracker->unmounted++;
    }
}

final class ConditionalSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        if (!$this->model->show->get()) {
            return \Phalanx\Theatron\Ui\column();
        }

        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class FailingBeforeSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        if ($this->model->throw) {
            throw new \RuntimeException('parent failed');
        }

        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class FailingAfterReplacementSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $child = \Phalanx\Theatron\Ui\mount(LabelSlotChildComponent::class, label: (string) $this->model->label->get());

        if ($this->model->throw) {
            throw new \RuntimeException('parent failed after replacement');
        }

        return \Phalanx\Theatron\Ui\column($child);
    }
}

final class FailingAfterNestedChildRenderParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $child = \Phalanx\Theatron\Ui\mount(NestedChildSlotComponent::class, label: 'nested', model: $this->model);
        $mounted = $ctx->mountSystem->resolve($child);

        if (!$mounted instanceof MountedComponent) {
            throw new \LogicException('Nested child mount did not resolve to a mounted component.');
        }

        $mounted->render($ctx);
        $this->model->mountedCountBeforeThrow = count($ctx->mountSystem->mounted());

        throw new \RuntimeException('parent failed after nested render');
    }
}

final class AmbientServiceSlotParentComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(ServiceConsumerComponent::class),
        );
    }
}

final class SlotOwnerScreen implements Screen
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class NestedParentSlotComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\mount(
                NestedChildSlotComponent::class,
                label: (string) $this->model->label->get(),
                model: $this->model,
            ),
        );
    }
}

final class NestedChildSlotComponent implements Component
{
    public function __construct(
        private string $label,
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $this->model->nestedChildRenderCount = ($this->model->nestedChildRenderCount ?? 0) + 1;

        return \Phalanx\Theatron\Ui\column(
            \Phalanx\Theatron\Ui\text($this->label),
            \Phalanx\Theatron\Ui\mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class FunctionSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $this->model->parentSignal->get();

        return column(
            mount(SlotChildComponent::class, input: $this->model->childSignal),
        );
    }
}

final class FunctionLabelSlotParentComponent implements Component
{
    public function __construct(
        private \stdClass $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return column(
            mount(LabelSlotChildComponent::class, label: (string) $this->model->label->get()),
        );
    }
}

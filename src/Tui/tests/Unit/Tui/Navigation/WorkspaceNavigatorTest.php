<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Navigation;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Tui\Core\MountedComponent;
use Phalanx\Tui\Tui\Core\MountedScreen;
use Phalanx\Tui\Tui\Core\MountSystem;
use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Core\ScreenContext;
use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Core\Screen;
use Phalanx\Tui\Tui\Navigation\Navigator;
use Phalanx\Tui\Tui\Navigation\WorkspaceNavigator;
use Phalanx\Tui\Tui\Reactive\Signal;
use Phalanx\Tui\Tui\Styles\Theme;
use Phalanx\Tui\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Fixtures — Greek-themed, not Agent
// ---------------------------------------------------------------------------

final class ZeusScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Zeus');
    }
}

final class ApolloScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Apollo');
    }
}

final class LeonidasScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Leonidas');
    }
}

final class SpartaOverlay implements Component
{
    public function __construct(
        private(set) string $label = 'sparta',
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text($this->label);
    }
}

final class OlympusOverlay implements Component
{
    public function __construct(
        private(set) Signal $title = new Signal('olympus'),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text($this->title->get());
    }
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

final class WorkspaceNavigatorTest extends TestCase
{
    // --- E.01 Navigator interface -------------------------------------------

    #[Test]
    public function implementsNavigator(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        self::assertInstanceOf(Navigator::class, $nav);
    }

    #[Test]
    public function activeReturnsInitialScreen(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        self::assertSame(ZeusScreen::class, $nav->active());
    }

    #[Test]
    public function goSwitchesActiveScreen(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->go(ApolloScreen::class);

        self::assertSame(ApolloScreen::class, $nav->active());
    }

    #[Test]
    public function goToSameScreenIsNoOp(): void
    {
        $system = $this->makeSystem();
        $nav = new WorkspaceNavigator($system, ZeusScreen::class);

        $before = $nav->activeWorkspace();
        $nav->go(ZeusScreen::class);

        self::assertSame($before, $nav->activeWorkspace());
    }

    #[Test]
    public function overlayPushesOntoStack(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        self::assertFalse($nav->hasOverlays());

        $nav->overlay(SpartaOverlay::class);

        self::assertTrue($nav->hasOverlays());
        self::assertCount(1, $nav->overlays());
    }

    #[Test]
    public function dismissPopsTopOverlay(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $nav->overlay(OlympusOverlay::class);

        self::assertCount(2, $nav->overlays());

        $nav->dismiss();

        self::assertCount(1, $nav->overlays());
    }

    #[Test]
    public function dismissAllClearsStack(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $nav->overlay(OlympusOverlay::class);

        $nav->dismissAll();

        self::assertFalse($nav->hasOverlays());
    }

    #[Test]
    public function dismissOnEmptyStackIsNoOp(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        // must not throw
        $nav->dismiss();

        self::assertFalse($nav->hasOverlays());
    }

    // --- E.02 Workspace persistence -----------------------------------------

    #[Test]
    public function activeWorkspaceIsMountedScreen(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        self::assertInstanceOf(MountedScreen::class, $nav->activeWorkspace());
    }

    #[Test]
    public function initialScreenMountedExactlyOnce(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $first = $nav->activeWorkspace();

        $nav->go(ApolloScreen::class);
        $nav->go(ZeusScreen::class);

        self::assertSame($first, $nav->activeWorkspace(), 'Same MountedScreen returned on revisit');
    }

    #[Test]
    public function workspaceMountedOnFirstVisitOnly(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->go(ApolloScreen::class);
        $apollo1 = $nav->activeWorkspace();

        $nav->go(ZeusScreen::class);
        $nav->go(ApolloScreen::class);
        $apollo2 = $nav->activeWorkspace();

        self::assertSame($apollo1, $apollo2, 'Workspace not re-mounted on second visit');
    }

    #[Test]
    public function workspaceSurvivesGoSwitch(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $zeus = $nav->activeWorkspace();

        $nav->go(ApolloScreen::class);

        self::assertFalse($zeus->isDisposed, 'Inactive workspace must not be disposed');
    }

    #[Test]
    public function multipleWorkspacesSwitchCorrectly(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->go(ApolloScreen::class);
        $nav->go(LeonidasScreen::class);

        self::assertSame(LeonidasScreen::class, $nav->active());

        $nav->go(ZeusScreen::class);

        self::assertSame(ZeusScreen::class, $nav->active());
    }

    #[Test]
    public function goMarksRevisitedWorkspaceDirtyForRepaint(): void
    {
        $system = $this->makeSystem();
        $nav = new WorkspaceNavigator($system, ZeusScreen::class);
        $workspace = $nav->activeWorkspace();

        $workspace->render(new ScreenContext(
            $this->createStub(TaskScope::class),
            Theme::default(),
            $nav,
            $system,
        ));
        self::assertFalse($workspace->isDirty);

        $nav->go(ApolloScreen::class);
        $nav->go(ZeusScreen::class);

        self::assertSame($workspace, $nav->activeWorkspace());
        self::assertTrue($workspace->isDirty);
    }

    #[Test]
    public function backMarksRestoredWorkspaceDirtyForRepaint(): void
    {
        $system = $this->makeSystem();
        $nav = new WorkspaceNavigator($system, ZeusScreen::class);
        $workspace = $nav->activeWorkspace();

        $workspace->render(new ScreenContext(
            $this->createStub(TaskScope::class),
            Theme::default(),
            $nav,
            $system,
        ));

        $nav->go(ApolloScreen::class);
        self::assertTrue($nav->back());

        self::assertSame($workspace, $nav->activeWorkspace());
        self::assertTrue($workspace->isDirty);
    }

    // --- E.03 Overlay lifecycle ---------------------------------------------

    #[Test]
    public function overlayIsMountedComponent(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);

        $overlays = $nav->overlays();
        self::assertInstanceOf(MountedComponent::class, $overlays[0]);
    }

    #[Test]
    public function dismissDisposesTopOverlay(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(OlympusOverlay::class);
        $overlay = $nav->overlays()[0];

        self::assertFalse($overlay->isDisposed);

        $nav->dismiss();

        self::assertTrue($overlay->isDisposed, 'Dismissed overlay must be disposed');
    }

    #[Test]
    public function dismissAllDisposesAllOverlays(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $nav->overlay(OlympusOverlay::class);

        $overlays = $nav->overlays();
        [$first, $second] = [$overlays[0], $overlays[1]];

        $nav->dismissAll();

        self::assertTrue($first->isDisposed);
        self::assertTrue($second->isDisposed);
    }

    #[Test]
    public function dismissDisposesOverlaySignals(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(OlympusOverlay::class);
        $overlay = $nav->overlays()[0];

        // Render once so the signal subscription is active.
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $system = $this->makeSystem();
        $overlay->render(new RenderContext($scope, Theme::default(), $system));

        $nav->dismiss();

        self::assertTrue($overlay->isDisposed);
    }

    #[Test]
    public function overlayWithParamsPassedThrough(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class, label: 'thermopylae');

        $overlay = $nav->overlays()[0];
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $system = $this->makeSystem();
        $ctx = new RenderContext($scope, Theme::default(), $system);

        $result = $overlay->render($ctx);

        self::assertInstanceOf(\Phalanx\Tui\Tui\Tdom\Element\TextElement::class, $result);
        self::assertSame('thermopylae', $result->content);
    }

    #[Test]
    public function dismissIsLIFO(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $nav->overlay(OlympusOverlay::class);

        $first = $nav->overlays()[0];
        $second = $nav->overlays()[1];

        $nav->dismiss();

        self::assertTrue($second->isDisposed, 'Last-in overlay disposed first');
        self::assertFalse($first->isDisposed, 'Earlier overlay still live');
    }

    #[Test]
    public function overlayStackIndependentFromWorkspace(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $workspace = $nav->activeWorkspace();

        $nav->dismissAll();

        self::assertFalse($workspace->isDisposed, 'Dismiss must not affect workspace');
    }

    #[Test]
    public function overlaysSurviveWorkspaceSwitch(): void
    {
        $nav = new WorkspaceNavigator($this->makeSystem(), ZeusScreen::class);

        $nav->overlay(SpartaOverlay::class);
        $overlay = $nav->overlays()[0];

        $nav->go(ApolloScreen::class);

        self::assertTrue($nav->hasOverlays(), 'Overlays persist across go()');
        self::assertFalse($overlay->isDisposed, 'Overlay not disposed on workspace switch');
        self::assertSame(ApolloScreen::class, $nav->active());
    }

    private function makeSystem(): MountSystem
    {
        return new MountSystem($this->createStub(\Phalanx\Scope\Scope::class));
    }
}

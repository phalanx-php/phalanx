<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Agent;

use Phalanx\Theatron\Agent\EffectApprovalReactor;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Overlay\EffectApprovalOverlay;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\PendingEffect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub Navigator that records overlay calls
// ---------------------------------------------------------------------------

final class RecordingNavigator implements Navigator
{
    public ?string $lastOverlayComponent = null;

    /** @var array<string, mixed> */
    public array $lastOverlayParams = [];

    /** @param class-string<Screen> $screen */
    public function go(string $screen): void
    {
    }

    public function back(): bool
    {
        return false;
    }

    /** @param class-string<\Phalanx\Theatron\Contract\Component> $component */
    public function overlay(string $component, mixed ...$params): void
    {
        $this->lastOverlayComponent = $component;
        /** @var array<string, mixed> $params */
        $this->lastOverlayParams = $params;
    }

    public function dismiss(): void
    {
    }

    public function dismissAll(): void
    {
    }

    /** @return class-string<Screen> */
    public function active(): string
    {
        /** @var class-string<Screen> */
        return \Phalanx\Theatron\Tests\Unit\Agent\StubScreen::class;
    }
}

// Minimal Screen stub so active() can return a valid class-string<Screen>.
final class StubScreen implements \Phalanx\Theatron\Contract\Screen
{
    public function __invoke(\Phalanx\Theatron\Context\ScreenContext $ctx): \Phalanx\Theatron\Tdom\Renderable
    {
        return \Phalanx\Theatron\Ui\text('stub');
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

final class EffectApprovalReactorTest extends TestCase
{
    #[Test]
    public function checkDoesNothingWhenIdle(): void
    {
        $store = new AppStore();
        $navigator = new RecordingNavigator();

        EffectApprovalReactor::check($store, $navigator);

        self::assertNull($navigator->lastOverlayComponent);
    }

    #[Test]
    public function checkDoesNothingWhenRunning(): void
    {
        $store = new AppStore();
        $store->activity = new ActivitySlice(status: ActivityStatus::Running);
        $navigator = new RecordingNavigator();

        EffectApprovalReactor::check($store, $navigator);

        self::assertNull($navigator->lastOverlayComponent);
    }

    #[Test]
    public function checkPushesOverlayWhenAwaitingApproval(): void
    {
        $store = new AppStore();
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write the thermopylae battle plan',
            arguments: ['target' => '/olympus/plans/thermopylae.php'],
            hazardLevel: 2,
        );
        $store->activity = new ActivitySlice()->awaitingApproval($effect);
        $navigator = new RecordingNavigator();

        EffectApprovalReactor::check($store, $navigator);

        self::assertSame(EffectApprovalOverlay::class, $navigator->lastOverlayComponent);
        self::assertSame($effect, $navigator->lastOverlayParams['effect']);
    }

    #[Test]
    public function checkDoesNothingWhenAwaitingButNoPendingEffect(): void
    {
        $store = new AppStore();
        $store->activity = new ActivitySlice(status: ActivityStatus::AwaitingApproval, pendingEffect: null);
        $navigator = new RecordingNavigator();

        EffectApprovalReactor::check($store, $navigator);

        self::assertNull($navigator->lastOverlayComponent);
    }
}

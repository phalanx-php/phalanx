<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Overlay;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Agent\AgentRuntime;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Overlay\OverlayPainter;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Overlay\EffectApprovalOverlay;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use Phalanx\Theatron\Template\Slice\PendingEffect;
use Phalanx\Theatron\Tests\Support\RecordingAgentExecutor;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectApprovalOverlayTest extends TestCase
{
    #[Test]
    public function rendersEffectPanel(): void
    {
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write to /olympus/config.php',
            arguments: [],
            hazardLevel: 0,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertInstanceOf(PanelElement::class, $result);
        self::assertSame('Effect Approval', $result->title);
    }

    #[Test]
    public function showsEffectKind(): void
    {
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write to /olympus/config.php',
            arguments: [],
            hazardLevel: 0,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertStringContainsString('file.write', $this->flattenText($result));
    }

    #[Test]
    public function showsEffectSummary(): void
    {
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write to /olympus/config.php',
            arguments: [],
            hazardLevel: 0,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertStringContainsString('Write to /olympus/config.php', $this->flattenText($result));
    }

    #[Test]
    public function showsArguments(): void
    {
        $effect = new PendingEffect(
            kind: 'process.run',
            summary: 'Execute the phalanx formation script',
            arguments: ['path' => '/sparta/bin/formation.sh', 'flags' => '--shield-wall'],
            hazardLevel: 2,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());
        $text = $this->flattenText($result);

        self::assertStringContainsString('path', $text);
        self::assertStringContainsString('/sparta/bin/formation.sh', $text);
        self::assertStringContainsString('flags', $text);
        self::assertStringContainsString('--shield-wall', $text);
    }

    #[Test]
    public function showsEmptyArgumentsMessage(): void
    {
        $effect = new PendingEffect(
            kind: 'file.read',
            summary: 'Read the Spartan codex',
            arguments: [],
            hazardLevel: 0,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertStringContainsString('No arguments', $this->flattenText($result));
    }

    #[Test]
    public function showsHazardLevelSafe(): void
    {
        $effect = new PendingEffect(
            kind: 'file.read',
            summary: 'Read the agora scrolls',
            arguments: [],
            hazardLevel: 0,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertStringContainsString('Safe', $this->flattenText($result));
    }

    #[Test]
    public function showsHazardLevelHigh(): void
    {
        $effect = new PendingEffect(
            kind: 'process.run',
            summary: 'Unleash the Spartan phalanx',
            arguments: [],
            hazardLevel: 3,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());

        self::assertStringContainsString('High', $this->flattenText($result));
    }

    #[Test]
    public function showsActionHints(): void
    {
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write the marathon dispatch',
            arguments: [],
            hazardLevel: 1,
        );

        $result = (new EffectApprovalOverlay($effect))($this->makeContext());
        $text = $this->flattenText($result);

        self::assertStringContainsString('Approve', $text);
        self::assertStringContainsString('Deny', $text);
    }

    #[Test]
    public function paintsApprovalCriticalDetailsInsideOverlayFrame(): void
    {
        $effect = new PendingEffect(
            kind: 'process.run',
            summary: 'Execute the phalanx formation script',
            arguments: ['path' => '/sparta/bin/formation.sh'],
            hazardLevel: 2,
            effectId: 'effect-visible',
        );
        $overlay = new EffectApprovalOverlay($effect);
        $bounds = Rect::sized(90, 28);
        $frame = $overlay->overlayFrame($bounds);
        $buffer = Buffer::empty($bounds->width, $bounds->height);
        $ctx = $this->makeContext();

        OverlayPainter::paint(
            $overlay($ctx),
            $buffer,
            $bounds,
            $frame,
            $ctx,
            new \stdClass(),
        );

        $text = self::bufferText($buffer);
        self::assertStringContainsString('process.run', $text);
        self::assertStringContainsString('Execute the phalanx formation script', $text);
        self::assertStringContainsString('/sparta/bin/formation.sh', $text);
        self::assertStringContainsString('Hazard: Medium', $text);
        self::assertStringContainsString('[A] Approve  [D] Deny', $text);
    }

    #[Test]
    public function approveKeyResolvesPendingEffectAndDismissesOverlay(): void
    {
        $effect = new PendingEffect(
            kind: 'file.write',
            summary: 'Write the dispatch',
            arguments: [],
            hazardLevel: 1,
            effectId: 'effect-approve',
        );
        $at = new DateTimeImmutable();
        $executor = new RecordingAgentExecutor(approvalCues: [
            new EffectAuthorized('cue-authorized', 1, 'activity-1', null, null, $at, 'effect-approve', 'grant-1'),
        ]);
        $store = new AppStore();
        $store->effects = $store->effects->appendRequested($effect);
        $store->activity = new ActivitySlice(pendingEffect: $effect);
        $navigator = new EffectApprovalOverlayRecordingNavigator();
        $overlay = new EffectApprovalOverlay(
            $effect,
            $store,
            $navigator,
            new AgentRuntime($store, $executor),
        );
        $overlay->onMount(new RecordingTaskScope());

        self::assertTrue($overlay->handleNormalKey(new KeyEvent('a')));
        self::assertSame([$effect], $executor->approvedEffects);
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(1, $navigator->dismissals);
        self::assertSame(EffectStatus::Approved, $store->effects->entries[0]->status);
        self::assertSame('grant-1', $store->effects->entries[0]->grantId);
    }

    #[Test]
    public function denyKeyResolvesPendingEffectAndDismissesOverlay(): void
    {
        $effect = new PendingEffect(
            kind: 'process.run',
            summary: 'Run the campaign',
            arguments: [],
            hazardLevel: 3,
            effectId: 'effect-deny',
        );
        $at = new DateTimeImmutable();
        $executor = new RecordingAgentExecutor(denialCues: [
            new EffectDenied('cue-denied', 1, 'activity-1', null, null, $at, 'effect-deny', ['user-denied']),
        ]);
        $store = new AppStore();
        $store->effects = $store->effects->appendRequested($effect);
        $store->activity = new ActivitySlice(pendingEffect: $effect);
        $navigator = new EffectApprovalOverlayRecordingNavigator();
        $overlay = new EffectApprovalOverlay(
            $effect,
            $store,
            $navigator,
            new AgentRuntime($store, $executor),
        );
        $overlay->onMount(new RecordingTaskScope());

        self::assertTrue($overlay->handleNormalKey(new KeyEvent('d')));
        self::assertSame([$effect], $executor->deniedEffects);
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(1, $navigator->dismissals);
        self::assertSame(EffectStatus::Denied, $store->effects->entries[0]->status);
        self::assertSame(['user-denied'], $store->effects->entries[0]->reasonCodes);
    }

    private static function bufferText(Buffer $buffer): string
    {
        $lines = [];

        for ($y = 0; $y < $buffer->height; $y++) {
            $line = '';

            for ($x = 0; $x < $buffer->width; $x++) {
                $line .= $buffer->get($x, $y)->char;
            }

            $lines[] = rtrim($line);
        }

        return implode("\n", $lines);
    }

    private function flattenText(mixed $element): string
    {
        if ($element instanceof TextElement) {
            $content = $element->content;
            return is_string($content) ? $content : '';
        }

        if ($element instanceof PanelElement) {
            return $this->flattenText($element->child);
        }

        if ($element instanceof ColumnElement) {
            return implode(' ', array_map($this->flattenText(...), $element->children));
        }

        if ($element instanceof DividerElement) {
            return '';
        }

        return '';
    }

    private function makeContext(): RenderContext
    {
        $scope = $this->createStub(TaskScope::class);
        $mountSystem = new MountSystem($scope);

        return new RenderContext($scope, Theme::default(), $mountSystem);
    }
}

final class EffectApprovalOverlayRecordingNavigator implements Navigator
{
    public int $dismissals = 0;

    public function go(string $screen): void
    {
    }

    public function back(): bool
    {
        return false;
    }

    public function overlay(string $component, mixed ...$params): void
    {
    }

    public function dismiss(): void
    {
        $this->dismissals++;
    }

    public function dismissAll(): void
    {
    }

    public function active(): string
    {
        return ChatScreen::class;
    }
}

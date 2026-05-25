<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Overlay;

use Phalanx\Harness\Agent\AgentRuntime;
use Phalanx\Harness\Template\AppStore;
use Phalanx\Harness\Template\Slice\PendingEffect;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\HasOverlayFrame;
use Phalanx\Theatron\Contract\Mountable;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Layout\Padding;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Overlay\OverlayFrame;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\divider;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\text;

class EffectApprovalOverlay implements Component, HasOverlayFrame, NormalModeHandler, Mountable
{
    private ?TaskScope $scope = null;

    public function __construct(
        private(set) PendingEffect $effect,
        private ?AppStore $store = null,
        private ?Navigator $navigator = null,
        private ?AgentRuntime $runtime = null,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $children = [
            ...self::renderEffectInfo($this->effect),
            divider(TdomStyle::of(size: Size::fixed(1))),
            ...self::renderArguments($this->effect),
            divider(TdomStyle::of(size: Size::fixed(1))),
            self::renderHazardLevel($this->effect),
            divider(TdomStyle::of(size: Size::fixed(1))),
            self::renderActions(),
        ];

        return panel('Effect Approval', column(...$children), TdomStyle::of(
            padding: Padding::all(1),
            color: $ctx->theme->overlayBorder,
            background: $ctx->theme->overlaySurface,
        ));
    }

    public function overlayFrame(Rect $bounds): OverlayFrame
    {
        return OverlayFrame::centered($bounds, 72, 20);
    }

    public function onMount(TaskScope $scope): void
    {
        $this->scope = $scope;
    }

    public function onUnmount(): void
    {
        $this->scope = null;
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if ($event->is('a') || $event->is('A')) {
            if ($this->scope !== null && $this->runtime !== null) {
                $this->runtime->approve($this->scope, $this->effect);
            } elseif ($this->store !== null) {
                $this->store->activity = $this->store->activity->effectResolved();
            }
            $this->navigator?->dismiss();

            return true;
        }

        if ($event->is('d') || $event->is('D')) {
            if ($this->scope !== null && $this->runtime !== null) {
                $this->runtime->deny($this->scope, $this->effect);
            } elseif ($this->store !== null) {
                $this->store->activity = $this->store->activity->effectResolved();
            }
            $this->navigator?->dismiss();

            return true;
        }

        return false;
    }

    /** @return list<Renderable> */
    private static function renderEffectInfo(PendingEffect $effect): array
    {
        return [
            text(sprintf('Kind: %s', $effect->kind)),
            text(sprintf('Effect: %s', $effect->effectId !== '' ? $effect->effectId : 'unknown')),
            text(sprintf('Summary: %s', $effect->summary)),
        ];
    }

    /** @return list<Renderable> */
    private static function renderArguments(PendingEffect $effect): array
    {
        if ($effect->arguments === []) {
            return [text('No arguments')];
        }

        $rows = [];
        foreach ($effect->arguments as $key => $value) {
            $rows[] = text(sprintf('%s: %s', $key, self::formatValue($value)));
        }

        return $rows;
    }

    private static function renderHazardLevel(PendingEffect $effect): Renderable
    {
        $label = match ($effect->hazardLevel) {
            0 => 'Safe',
            1 => 'Low',
            2 => 'Medium',
            default => 'High',
        };

        return text(sprintf('Hazard: %s', $label));
    }

    private static function renderActions(): Renderable
    {
        return text('[A] Approve  [D] Deny');
    }

    private static function formatValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Screens;

use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\TimelineEntry;
use Phalanx\Tui\Core\Focusable;
use Phalanx\Tui\Core\HasFocusables;
use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Kit\InputComposer;
use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\column;
use function Phalanx\Tui\Kit\grid;
use function Phalanx\Tui\Kit\panel;
use function Phalanx\Tui\Kit\scrollable;
use function Phalanx\Tui\Kit\statusLine;
use function Phalanx\Tui\Kit\text;

class WorkspaceScreen implements Screen, HasFocusables
{
    private InputComposer $composer;

    public function __construct(
        private Store $store,
        ?InputPromptSubmitter $submitter = null,
    ) {
        $this->composer = InputComposer::empty(
            prompt: 'You > ',
            onSubmit: $submitter,
        );
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        $mainLines = max(4, $ctx->height - 16);
        $input = ($this->composer)(new RenderContext(
            scope: $ctx->scope,
            theme: $ctx->theme,
            mountSystem: $ctx->mountSystem,
            renderDiagnostics: $ctx->renderDiagnostics,
        ));

        return column(
            grid(
                [Size::fr(2), Size::fr(1)],
                panel('Chat', scrollable($this->timelineText(), maxLines: $mainLines)),
                panel('Plan', scrollable($this->planText(), maxLines: $mainLines)),
            ),
            grid(
                [Size::fr(1), Size::fr(1)],
                panel('Runtime', scrollable($this->runtimeText(), maxLines: 6)),
                panel('DevTools', scrollable($this->devToolsText(), maxLines: 6)),
            ),
            panel(
                'Input',
                $input,
            ),
            statusLine(
                text(sprintf('stage: %s', $this->store->loop->stage->value)),
                text(sprintf('plan: %s', $this->store->workPlan->plan->status->value)),
                text(sprintf('pane: %s', $this->store->workspaceView->activePane)),
            ),
        );
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['input', $this->composer]];
    }

    private static function singleLine(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\n"], ' ', $text));
    }

    private function timelineText(): string
    {
        if ($this->store->messages->entries === []) {
            return 'No timeline entries.';
        }

        return implode("\n", array_map(
            static fn (TimelineEntry $entry): string => sprintf(
                '%s %s%s',
                $entry->kind->value,
                self::singleLine($entry->summary),
                $entry->workItemId === null ? '' : sprintf(' [%s]', $entry->workItemId),
            ),
            $this->store->messages->entries,
        ));
    }

    private function planText(): string
    {
        $items = $this->store->workPlan->plan->items();
        if ($items === []) {
            return 'No work planned.';
        }

        return implode("\n", array_map(
            static fn (WorkPlanItem $item): string => sprintf(
                '%s %s - %s',
                $item->status->value,
                $item->workItem->id,
                self::singleLine($item->workItem->prompt),
            ),
            $items,
        ));
    }

    private function runtimeText(): string
    {
        return implode("\n", [
            sprintf('session: %s', $this->store->runtime->sessionId ?? 'none'),
            sprintf('health: %s', $this->store->runtime->health ?? 'unknown'),
            sprintf('replay: %s', $this->store->runtime->replaying ? 'yes' : 'no'),
            sprintf('context: %d', $this->store->context->pressure),
            sprintf('focus: %s', $this->store->context->activeFocus ?? 'none'),
            sprintf('participants: %s', $this->participantsText()),
            sprintf('review: %s', $this->reviewText()),
        ]);
    }

    private function devToolsText(): string
    {
        return implode("\n", [
            sprintf('tab: %s', $this->store->devTools->activeTab),
            sprintf('event: %s', $this->store->devTools->selectedEventId ?? 'none'),
            sprintf(
                'filters: %s',
                $this->store->devTools->filters === [] ? 'none' : implode(', ', $this->store->devTools->filters),
            ),
        ]);
    }

    private function participantsText(): string
    {
        if ($this->store->participants->participants === []) {
            return 'none';
        }

        return implode(', ', $this->store->participants->participants);
    }

    private function reviewText(): string
    {
        $latest = $this->store->reviews->verdicts[array_key_last($this->store->reviews->verdicts)] ?? null;

        if (!$latest instanceof ReviewVerdict) {
            return 'none';
        }

        return $latest->reason ?? $latest->status->value;
    }
}

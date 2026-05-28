#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreHandle;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class MessageFeedSlice implements Slice
{
    public string $key {
        get => 'agora.messages';
    }

    public function __construct(
        private(set) string $log = '',
        private(set) int $count = 0,
    ) {
    }
}

final class AgoraStatusSlice implements Slice
{
    public string $key {
        get => 'agora.status';
    }

    public function __construct(
        private(set) int $agents = 3,
        private(set) int $dispatched = 0,
        private(set) string $phase = 'idle',
    ) {
    }
}

final class AgoraMetricsSlice implements Slice
{
    public string $key {
        get => 'agora.metrics';
    }

    public function __construct(
        private(set) int $frames = 0,
        private(set) int $dirtyRequests = 0,
        private(set) int $memReal = 0,
        private(set) int $memRealDelta = 0,
        private(set) int $memRealPeak = 0,
        private(set) int $memZend = 0,
        private(set) int $memZendDelta = 0,
        private(set) int $memZendPeak = 0,
        private(set) int $storeSlices = 0,
        private(set) float $uptimeSeconds = 0.0,
    ) {
    }
}

final class AgoraMetricsPanel implements StatefulComponent
{
    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1) . 'K';
    }

    private static function delta(int $bytes): string
    {
        $sign = $bytes >= 0 ? '+' : '';

        return $sign . number_format($bytes / 1024, 1) . 'K';
    }

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $m = $ctx->lens(AgoraMetricsSlice::class)->value;
        $feed = $ctx->lens(MessageFeedSlice::class)->value;
        $status = $ctx->lens(AgoraStatusSlice::class)->value;

        return $ctx->ui->column(
            $ctx->ui->text(sprintf(
                ' Real: %s (%s) | Peak: %s',
                self::kb($m->memReal), self::delta($m->memRealDelta), self::kb($m->memRealPeak),
            ), Style::of(size: Size::fixed(1), color: Color::brightWhite())),
            $ctx->ui->text(sprintf(
                ' Zend: %s (%s) | Peak: %s',
                self::kb($m->memZend), self::delta($m->memZendDelta), self::kb($m->memZendPeak),
            ), Style::of(size: Size::fixed(1), color: Color::indexed(250))),
            $ctx->ui->text(sprintf(
                ' Frames: %d | Dirty: %d | Messages: %d | Uptime: %.1fs',
                $m->frames, $m->dirtyRequests, $feed->count, $m->uptimeSeconds,
            ), Style::of(size: Size::fixed(1), color: Color::indexed(250))),
            $ctx->ui->text(sprintf(
                ' Agents: %d | Dispatched: %d | Phase: %s | Slices: %d',
                $status->agents, $status->dispatched, $status->phase, $m->storeSlices,
            ), Style::of(size: Size::fixed(1), color: Color::indexed(244))),
        );
    }
}

final class AgoraDashboard implements StatefulComponent, InputTarget
{
    public const array AGENTS = ['Leonidas', 'Themistocles', 'Pericles'];

    public const array DISPATCHES = [
        'Scouts report movement near Thermopylae',
        'Fleet assembling at Salamis',
        'Supplies requisitioned from Corinth',
        'Training drills complete on the Eurotas',
        'Envoy dispatched to the Oracle at Delphi',
        'Fortifications reinforced at the Isthmus',
        'Signal fires lit along the coast',
        'Cavalry reports from the plains of Argos',
    ];

    private ?Signal $selectedAgent = null;
    private ?Signal $input = null;

    /** @var ?StoreHandle<MessageFeedSlice> */
    private ?StoreHandle $feed = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->selectedAgent = $ctx->signal(0, key: 'agent');
        $this->input = $ctx->signal('', key: 'input');
        $this->feed = $ctx->lens(MessageFeedSlice::class);

        $status = $ctx->lens(AgoraStatusSlice::class);

        $summary = $ctx->computed(static function () use ($status): string {
            $s = $status->value;

            return sprintf('%d agents | %d dispatched | %s', $s->agents, $s->dispatched, $s->phase);
        }, key: 'summary');

        $selected = self::AGENTS[$this->selectedAgent->value] ?? 'unknown';

        $agentRows = [];

        foreach (self::AGENTS as $i => $name) {
            $marker = $i === $this->selectedAgent->value ? '>' : ' ';
            $color = $i === $this->selectedAgent->value ? Color::brightCyan() : Color::brightWhite();
            $agentRows[] = $ctx->ui->text(sprintf(' %s %s', $marker, $name), Style::of(size: Size::fixed(1), color: $color));
        }

        $agentPanel = $ctx->ui->panel(
            'Agents',
            $ctx->ui->column(...$agentRows),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightCyan()),
        );

        $feedContent = $this->feed->value->log === '' ? 'Awaiting dispatches...' : $this->feed->value->log;

        $feedPanel = $ctx->ui->panel(
            'Dispatch Feed',
            $ctx->ui->column(
                $ctx->ui->scrollable($feedContent, maxLines: 12),
                $ctx->ui->divider(Style::of(size: Size::fixed(1), color: Color::indexed(240))),
                $ctx->ui->text(
                    sprintf(' %s> %s', $selected, $this->input->value),
                    Style::of(size: Size::fixed(1), color: Color::brightGreen()),
                ),
            ),
            style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::brightGreen()),
        );

        $controls = $ctx->ui->text(
            ' [Up/Down] Agent  [Type] Compose  [Enter] Send  [q] Quit ',
            Style::of(size: Size::fixed(1), color: Color::indexed(240)),
        );

        return $ctx->ui->column(
            $ctx->ui->row(
                $ctx->ui->text('AGORA', Style::of(size: Size::fill(), color: Color::brightCyan())),
                $ctx->ui->text(sprintf(' %s ', $summary->value), Style::of(color: Color::brightGreen())),
            ),
            $ctx->ui->row($agentPanel, $feedPanel),
            $controls,
        );
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        if ($event->is(Key::Up) && $this->selectedAgent !== null) {
            $this->selectedAgent->value = max(0, $this->selectedAgent->value - 1);

            return true;
        }

        if ($event->is(Key::Down) && $this->selectedAgent !== null) {
            $this->selectedAgent->value = min(count(self::AGENTS) - 1, $this->selectedAgent->value + 1);

            return true;
        }

        if ($event->is(Key::Enter) && $this->input !== null && $this->feed !== null && $this->input->value !== '') {
            $agent = self::AGENTS[$this->selectedAgent?->value ?? 0];
            $message = $this->input->value;
            $this->input->value = '';

            $this->feed->update(static fn(MessageFeedSlice $f): MessageFeedSlice => new MessageFeedSlice(
                log: $f->log === '' ? "[{$agent}] {$message}" : $f->log . "\n[{$agent}] {$message}",
                count: $f->count + 1,
            ));

            return true;
        }

        if ($event->is(Key::Backspace) && $this->input !== null) {
            $this->input->value = mb_substr($this->input->value, 0, -1);

            return true;
        }

        if ($event->is(Key::Space) && $this->input !== null) {
            $this->input->value .= ' ';

            return true;
        }

        $char = $event->char();
        if ($char !== null && $this->input !== null) {
            $this->input->value .= $char;

            return true;
        }

        return false;
    }
}

exit(Archon::command('agora', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $devtools = $ctx->options->flag('devtools');

    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'agora',
        MessageFeedSlice::class,
        AgoraStatusSlice::class,
        AgoraMetricsSlice::class,
    ));
    $registry->start($ctx);

    $writer = $registry->writer();
    $writer->set(new AgoraStatusSlice(agents: 3, dispatched: 0, phase: 'mustering'));

    $mount = new MountedComponent(new AgoraDashboard(), $ctx, $registry->lens());
    $devtoolsMount = $devtools ? new MountedComponent(new AgoraMetricsPanel(), $ctx, $registry->lens()) : null;
    $focus = new FocusManager();
    $parser = new EventParser();

    $mount->render();
    $focus->register('agora', $mount);
    $focus->focus('agora');

    if ($maxFrames !== null) {
        foreach ($parser->parse('status') as $event) {
            $focus->dispatch($event);
        }
    }

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-15-agora.bin' : null,
    ));

    $ui = new Ui();
    $w = $stage->width();
    $h = $stage->height();
    $dtHeight = $devtools ? 6 : 0;
    $mainRegion = $stage->region('main', Rect::of(0, 0, $w, $h - 1 - $dtHeight));
    $devtoolsRegion = $devtools ? $stage->region('devtools', Rect::of(0, $h - 1 - $dtHeight, $w, $dtHeight)) : null;
    $barRegion = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($mainRegion, $devtoolsRegion, $barRegion, $devtools): void {
        $dtHeight = $devtools ? 6 : 0;
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
        $mainRegion->resize(Rect::of(0, 0, $nw, $nh - 1 - $dtHeight));
        $devtoolsRegion?->resize(Rect::of(0, $nh - 1 - $dtHeight, $nw, $dtHeight));
    });

    $stage->onInput(static function (InputEvent $event) use ($focus, $mount, $stage): void {
        if (!$focus->dispatch($event)) {
            return;
        }

        if ($mount->isDirty) {
            $stage->requestFrame();
        }
    });

    $frames = 0;
    $dirtyCount = 0;
    $needsDraw = true;
    $startTime = hrtime(true);
    $sliceCount = 3;
    $baseReal = memory_get_usage(true);
    $baseZend = memory_get_usage(false);

    $stage->onDraw(static function () use (
        $ui,
        $writer,
        $mount,
        $devtoolsMount,
        $devtools,
        $mainRegion,
        $devtoolsRegion,
        $barRegion,
        $startTime,
        $sliceCount,
        $baseReal,
        $baseZend,
        &$frames,
        &$dirtyCount,
        &$needsDraw,
    ): void {
        $memReal = memory_get_usage(true);
        $memZend = memory_get_usage(false);
        $memRealPeak = memory_get_peak_usage(true);
        $memZendPeak = memory_get_peak_usage(false);

        $frames++;

        if ($frames % 30 === 0 && $frames <= 300) {
            $agent = AgoraDashboard::AGENTS[array_rand(AgoraDashboard::AGENTS)];
            $dispatch = AgoraDashboard::DISPATCHES[array_rand(AgoraDashboard::DISPATCHES)];

            $writer->update(MessageFeedSlice::class, static fn(MessageFeedSlice $f): MessageFeedSlice => new MessageFeedSlice(
                log: $f->log === '' ? "[{$agent}] {$dispatch}" : $f->log . "\n[{$agent}] {$dispatch}",
                count: $f->count + 1,
            ));

            $writer->update(AgoraStatusSlice::class, static fn(AgoraStatusSlice $s): AgoraStatusSlice => new AgoraStatusSlice(
                agents: $s->agents,
                dispatched: $s->dispatched + 1,
                phase: 'active',
            ));
        }

        $mainDirty = $mount->consumeDirty();

        if ($mainDirty) {
            $dirtyCount++;
        }

        if ($devtools) {
            $uptime = (hrtime(true) - $startTime) / 1_000_000_000;

            $writer->update(AgoraMetricsSlice::class, static fn(AgoraMetricsSlice $m): AgoraMetricsSlice => new AgoraMetricsSlice(
                frames: $frames,
                dirtyRequests: $dirtyCount,
                memReal: $memReal,
                memRealDelta: $memReal - $baseReal,
                memRealPeak: $memRealPeak,
                memZend: $memZend,
                memZendDelta: $memZend - $baseZend,
                memZendPeak: $memZendPeak,
                storeSlices: $sliceCount,
                uptimeSeconds: $uptime,
            ));
        }

        $dtDirty = $devtoolsMount?->consumeDirty() ?? false;

        if ($needsDraw || $mainDirty) {
            $mainRegion->draw($mount->render());
        }

        if ($devtoolsMount !== null && $devtoolsRegion !== null && ($needsDraw || $dtDirty)) {
            $devtoolsRegion->draw($ui->panel(
                'DevTools',
                $devtoolsMount->render(),
                style: Style::of(border: Border::Rounded, color: Color::indexed(240)),
            ));
        }

        $needsDraw = false;

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Agora | Frame: %d ', $frames),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    ' TH-C.01 ',
                    style: Style::of(color: Color::brightCyan(), background: Color::indexed(236)),
                ),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));
    });

    if ($maxFrames !== null) {
        $stage->start($ctx);

        while ($frames < $maxFrames) {
            $ctx->delay(0.01);
        }

        $stage->stop();
        $devtoolsMount?->dispose();
        $mount->dispose();

        return 0;
    }

    $stage->run($ctx);
    $devtoolsMount?->dispose();
    $mount->dispose();

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
    Opt::flag('devtools', desc: 'Show DevTools metrics panel'),
]))->default('agora')->run(array_slice($_SERVER['argv'] ?? [], 1)));

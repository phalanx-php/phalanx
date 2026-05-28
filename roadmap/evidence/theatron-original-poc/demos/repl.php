#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Iris\HttpClient;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyRegistry;
use Phalanx\Theatron\Demos\Repl\ConversationLog;
use Phalanx\Theatron\Demos\Repl\Reactor\AgentBridgeReactor;
use Phalanx\Theatron\Demos\Repl\Reactor\ReplEventRouter;
use Phalanx\Theatron\Demos\Repl\Render\ConversationRenderer;
use Phalanx\Theatron\Demos\Repl\Render\DevToolsOverlay;
use Phalanx\Theatron\Demos\Repl\Render\FocusedViewRenderer;
use Phalanx\Theatron\Demos\Repl\Render\InputRenderer;
use Phalanx\Theatron\Demos\Repl\Render\SettingsOverlay;
use Phalanx\Theatron\Demos\Repl\Render\SettingsPage;
use Phalanx\Theatron\Demos\Repl\Screen\ConversationScreen;
use Phalanx\Theatron\Demos\Repl\Screen\DevToolsScreen;
use Phalanx\Theatron\Demos\Repl\Screen\FocusedViewScreen;
use Phalanx\Theatron\Demos\Repl\Screen\LlmRequestDetailScreen;
use Phalanx\Theatron\Demos\Repl\Screen\ScreenStack;
use Phalanx\Theatron\Demos\Repl\Screen\SettingsScreen;
use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedViewSlice;
use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Stream\TheatronStream;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

exit(Archon::command('repl', static function (CommandContext $ctx): int {
    $dirty = new DirtyBatch();
    $stream = new TheatronStream($dirty);
    $ui = new Ui();
    $stage = Stage::boot(new StageConfig(
        activeIntervalUs: 33_000,
        handleInput: true,
        defaultExitHandler: false,
    ));

    $registry = StoreRegistry::fromDefinitions(
        Store::memory(
            'repl',
            ConvoSlice::class,
            InputSlice::class,
            AgentStatusSlice::class,
            FocusedViewSlice::class,
            SettingsSlice::class,
            LlmRequestSlice::class,
        ),
    );
    $registry->start($ctx);

    $writer = $registry->writer();
    $lens = $registry->lens();

    $reactorContext = new ReactorContext(
        scope: $ctx,
        lens: $lens,
        writer: $writer,
        dirty: $dirty,
    );

    $httpClient = new HttpClient();
    $convoLog = new ConversationLog(sys_get_temp_dir() . '/theatron-repl-' . getmypid() . '.jsonl');
    $ollamaModel = detectOllama($ctx, $httpClient);

    if ($ollamaModel !== null) {
        $bridge = new AgentBridgeReactor(httpClient: $httpClient, ollamaModel: $ollamaModel, log: $convoLog);
    } else {
        $bridge = new AgentBridgeReactor(log: $convoLog);
    }

    $bridge->start($ctx, $stream);

    if ($ollamaModel !== null) {
        $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->withModelName($ollamaModel));
    }

    $router = new ReplEventRouter($bridge, $convoLog);
    $router->subscribe($stream, $reactorContext);

    $stream->start($ctx);

    $convo = new ConversationRenderer($lens);
    $input = new InputRenderer($lens);
    $devtools = new DevToolsOverlay($lens);
    $settingsOverlay = new SettingsOverlay();
    $settingsPage = new SettingsPage();
    $focusedView = new FocusedViewRenderer($convo->markdown);

    $globals = new HotkeyRegistry();
    $globals->bind(new HotkeyBinding('c', ctrl: true, label: '^C:quit', handler: static function (HotkeyContext $ctx): void {
        $ctx->scope->cancellation()->cancel();
    }));
    $stack = new ScreenStack($globals);
    $stack->register(new ConversationScreen($convo, $input, $convoLog));
    $stack->register(new FocusedViewScreen($focusedView));
    $stack->register(new SettingsScreen($settingsPage));
    $stack->register(new DevToolsScreen($devtools));
    $stack->register(new LlmRequestDetailScreen());
    $stack->push('conversation');

    $hotkeyCtx = new HotkeyContext(
        scope: $ctx,
        writer: $writer,
        lens: $lens,
        dirty: $dirty,
        stack: $stack,
        stage: $stage,
        stream: $stream,
    );

    $stage->onInput(static function (InputEvent $event) use ($globals, $stack, $hotkeyCtx, $dirty, $writer): void {
        if (!$event instanceof KeyEvent) {
            return;
        }

        if ($globals->dispatch($event, $hotkeyCtx)) {
            $dirty->request();

            return;
        }

        if ($event->is(Key::Escape)) {
            $leaving = $stack->topName();

            if ($stack->pop()) {
                if ($leaving === 'focused-view') {
                    $writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->refocus());
                    $writer->update(FocusedViewSlice::class, static fn(FocusedViewSlice $s): FocusedViewSlice => $s->reset());
                }

                $dirty->request();
            }

            return;
        }

        if ($stack->dispatch($event, $hotkeyCtx)) {
            $dirty->request();
        }
    });

    $w = $stage->width();
    $h = $stage->height();
    $mainRegion = $stage->region('main', Rect::of(0, 0, $w, $h));
    $backdropRegion = $stage->region('backdrop', Rect::of(0, 0, 0, 0), new RegionConfig(zIndex: 1));
    $overlayRegion = $stage->region('overlay', Rect::of(0, 0, 0, 0), new RegionConfig(zIndex: 2));
    $backdropCache = (object) ['w' => 0, 'h' => 0, 'renderable' => null];

    $stage->onResize(static function (int $nw, int $nh) use ($mainRegion, $backdropCache, $dirty): void {
        $mainRegion->resize(Rect::of(0, 0, $nw, $nh));
        $backdropCache->w = 0;
        $dirty->request();
    });

    $stage->onDraw(static function () use ($ui, $dirty, $stack, $hotkeyCtx, $mainRegion, $backdropRegion, $backdropCache, $overlayRegion, $stage, $input, $lens, $writer): void {
        $agentStatus = $lens->handle(AgentStatusSlice::class)->value->status;

        if ($agentStatus !== 'idle') {
            $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->tick());
            $dirty->request();
        }

        if (!$dirty->consume()) {
            return;
        }

        $w = $stage->width();
        $h = $stage->height();

        $spacer = $ui->text(Line::from(Span::plain('')), Style::of(size: Size::fixed(1)));

        if ($stack->isTopOverlay()) {
            $baseBody = $stack->renderBase($ui, $hotkeyCtx, $w, $h - 2);
            $baseBar = $input->renderStatusBar($ui, $w, $hotkeyCtx);
            $mainRegion->draw(new ColumnElement([$baseBody, $spacer, $baseBar]));

            $bh = $h - 2;
            $backdropRegion->resize(Rect::of(0, 0, $w, $bh));

            if ($backdropCache->w !== $w || $backdropCache->h !== $bh) {
                $dimLine = Line::from(Span::styled(str_repeat(' ', $w), TextStyle::new()->bg(Color::indexed(240))));
                $dimRows = [];
                for ($i = 0; $i < $bh; $i++) {
                    $dimRows[] = $ui->text($dimLine, style: Style::of(size: Size::fixed(1)));
                }
                $backdropCache->w = $w;
                $backdropCache->h = $bh;
                $backdropCache->renderable = new ColumnElement($dimRows);
            }

            $backdropRegion->draw($backdropCache->renderable);

            $ow = (int) ($w * 0.6);
            $oh = (int) ($h * 0.6);
            $ox = (int) (($w - $ow) / 2);
            $oy = (int) (($h - $oh) / 2);
            $overlayRegion->resize(Rect::of($ox, $oy, $ow, $oh));

            $overlayBody = $stack->renderTop($ui, $hotkeyCtx, $ow, $oh);
            $overlayRegion->draw($overlayBody);
        } else {
            if ($overlayRegion->area->width > 0) {
                $overlayRegion->resize(Rect::of(0, 0, 0, 0));
                $backdropRegion->resize(Rect::of(0, 0, 0, 0));
            }

            $body = $stack->render($ui, $hotkeyCtx, $w, $h - 2);
            $bar = $input->renderStatusBar($ui, $w, $hotkeyCtx);
            $mainRegion->draw(new ColumnElement([$body, $spacer, $bar]));
        }
    });

    try {
        $stage->run($ctx);
    } finally {
        $stage->stop();
        $bridge->cancel();
        $stream->stop();
        @unlink($convoLog->path);
    }

    return 0;
}, new CommandConfig())->default('repl')->run(array_slice($_SERVER['argv'] ?? [], 1)));

function detectOllama(CommandContext $ctx, HttpClient $client): ?string
{
    try {
        $response = $client->get($ctx, 'http://localhost:11434/api/tags');
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        $models = $data['models'] ?? [];

        $best = null;
        $bestScore = -1;

        foreach ($models as $model) {
            $name = strtolower($model['name'] ?? '');
            $family = strtolower($model['details']['family'] ?? '');

            if ($family === 'nomic-bert' || str_contains($name, 'embed')) {
                continue;
            }

            if (str_contains($name, 'coder') || str_contains($name, 'code')) {
                continue;
            }

            $score = match (true) {
                str_starts_with($name, 'qwen3') => 100,
                str_starts_with($name, 'llama3.3'),
                str_starts_with($name, 'llama3.2'),
                str_starts_with($name, 'llama3.1') => 90,
                str_starts_with($name, 'mistral') && str_contains($name, 'instruct') => 70,
                str_starts_with($name, 'mistral') => 60,
                str_starts_with($name, 'qwen2.5') => 50,
                str_starts_with($name, 'llama3') => 40,
                default => 10,
            };

            if ($score > $bestScore) {
                $best = $model['name'];
                $bestScore = $score;
            }
        }

        return $best;
    } catch (Throwable) {
        return null;
    }
}

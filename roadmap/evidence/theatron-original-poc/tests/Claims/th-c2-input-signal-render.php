#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Buffer\Buffer;
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
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class ComposerProbe implements StatefulComponent, InputTarget
{
    public ?Signal $buffer = null;
    public int $handled = 0;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->buffer = $ctx->signal('');

        return new Ui()->text('input: ' . $this->buffer->value);
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent || !$this->buffer instanceof Signal) {
            return false;
        }

        if ($event->is(Key::Backspace)) {
            $this->buffer->value = mb_substr((string) $this->buffer->value, 0, -1);
            $this->handled++;

            return true;
        }

        if (!$event->isChar()) {
            return false;
        }

        $char = $event->char();

        if ($char === null) {
            return false;
        }

        $this->buffer->value .= $char;
        $this->handled++;

        return true;
    }
}

$component = new ComposerProbe();
$mount = new MountedComponent($component);
$focus = new FocusManager();
$parser = new EventParser();

$mount->render();
$focus->register('composer', $mount);
$focus->focus('composer');

assertTrue($focus->activeName() === 'composer', 'focused input target is active');

foreach ($parser->parse('ab c') as $event) {
    assertTrue($focus->dispatch($event), 'parsed keyboard event routes through the focused mount');
}

assertTrue($component->buffer instanceof Signal, 'input component mounted a buffer signal');
assertTrue($component->buffer->value === 'ab c', 'parsed keyboard bytes including space mutate the focused signal');
assertTrue($component->handled === 4, 'focused component handled four parsed key events including space');
assertTrue($mount->renderRequests === 1, 'input batch coalesces into one render request');
assertTrue($mount->isDirty, 'input signal writes mark the mount dirty');
assertTrue($mount->consumeDirty(), 'input render batch is consumable');
assertTrue(!$mount->isDirty, 'mount is clean after consuming the render batch');

$renderable = $mount->render();
$buffer = Buffer::empty(20, 1);
Painter::paint($renderable, new PaintContext(Rect::sized(20, 1), $buffer));

assertTrue($buffer->get(7, 0)->char === 'a', 'rendered buffer includes first typed character');
assertTrue($buffer->get(8, 0)->char === 'b', 'rendered buffer includes second typed character');
assertTrue($buffer->get(9, 0)->char === ' ', 'rendered buffer includes space typed via Key::Space');

foreach ($parser->parse("\x7f") as $event) {
    assertTrue($focus->dispatch($event), 'parsed backspace routes through the focused mount');
}

assertTrue($component->buffer->value === 'ab ', 'backspace mutates the focused signal through the parser path');
assertTrue($mount->renderRequests === 2, 'second input batch requests one additional render');

$captureFile = tempnam(sys_get_temp_dir(), 'theatron-th-c2-');
assertTrue(is_string($captureFile), 'capture file is available');
$stream = fopen('php://temp', 'w+');
assertTrue(is_resource($stream), 'capture stream is available');

$stage = Stage::boot(new StageConfig(
    screenMode: ScreenMode::Inline,
    stream: $stream,
    captureFile: $captureFile,
));
$region = $stage->region('composer', Rect::sized(20, 1));
$frameRenders = 0;

$stage->onDraw(static function (Stage $stage) use ($mount, $region, &$frameRenders): void {
    if (!$mount->consumeDirty()) {
        return;
    }

    $region->draw($mount->render());
    $stage->requestFrame();
    $frameRenders++;
});

$running = new ReflectionProperty($stage, 'running');
$running->setValue($stage, true);

$tick = new ReflectionMethod($stage, 'tick');
$tick->invoke($stage);

$firstFrame = file_get_contents($captureFile);
assertTrue(is_string($firstFrame), 'first frame capture is readable');
assertTrue($frameRenders === 1, 'one dirty input batch renders one stage frame');
assertTrue(str_contains($firstFrame, 'input:'), 'scheduled input frame contains the prompt');
assertTrue(str_contains($firstFrame, 'ab'), 'scheduled input frame contains the latest signal value');

$tick->invoke($stage);

$secondFrame = file_get_contents($captureFile);
assertTrue($secondFrame === $firstFrame, 'clean mount emits no second stage frame');

$fullRedraw = new ReflectionProperty($stage, 'fullRedraw');
$frameRequested = new ReflectionProperty($stage, 'frameRequested');

assertTrue($fullRedraw->getValue($stage) === false, 'input batch does not leave full redraw requested');
assertTrue($frameRequested->getValue($stage) === false, 'input batch consumes the scheduled frame');
assertTrue(substr_count($secondFrame, "\033[2J") === 0, 'input batch emits no clear-screen redraw');

unlink($captureFile);

fwrite(STDOUT, "TH-C2 input signal render claim passed\n");

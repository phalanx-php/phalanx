#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stage {
    final class MemoryCacheProbe
    {
        public static int $calls = 0;
    }

    function gc_mem_caches(): bool
    {
        MemoryCacheProbe::$calls++;

        return true;
    }
}

namespace {
    require __DIR__ . '/../../vendor/autoload.php';

    use Phalanx\Theatron\Buffer\Rect;
    use Phalanx\Theatron\Stage\MemoryCacheProbe;
    use Phalanx\Theatron\Stage\ScreenMode;
    use Phalanx\Theatron\Stage\Stage;
    use Phalanx\Theatron\Stage\StageConfig;
    use Phalanx\Theatron\Tdom\Ui;

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function stageFor(bool $flushMemoryCaches): Stage
    {
        $stream = fopen('php://temp', 'w+');
        assertTrue(is_resource($stream), 'stage stream opened');

        return Stage::boot(new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            stream: $stream,
            flushMemoryCaches: $flushMemoryCaches,
        ));
    }

    function drawingStageFor(bool $flushMemoryCaches): Stage
    {
        $stage = stageFor($flushMemoryCaches);
        $region = $stage->region('probe', Rect::sized(20, 1));
        $ui = new Ui();

        $stage->onDraw(static function (Stage $stage) use ($region, $ui): void {
            $region->draw($ui->text('memory policy'));
            $stage->requestFrame();
        });

        return $stage;
    }

    function tick(Stage $stage, int $times = 1): void
    {
        $running = new ReflectionProperty($stage, 'running');
        $running->setValue($stage, true);

        $tick = new ReflectionMethod($stage, 'tick');

        for ($i = 0; $i < $times; $i++) {
            $tick->invoke($stage);
        }
    }

    MemoryCacheProbe::$calls = 0;
    tick(drawingStageFor(flushMemoryCaches: false), 60);

    assertTrue(
        MemoryCacheProbe::$calls === 0,
        'natural memory policy does not flush allocator caches during rendered frames',
    );

    MemoryCacheProbe::$calls = 0;
    tick(drawingStageFor(flushMemoryCaches: true), 60);

    assertTrue(
        MemoryCacheProbe::$calls === 1,
        'cache-flush policy preserves the existing 60-frame allocator cache flush',
    );

    $idleStage = stageFor(flushMemoryCaches: false);
    $running = new ReflectionProperty($idleStage, 'running');
    $fullRedraw = new ReflectionProperty($idleStage, 'fullRedraw');
    $frameRequested = new ReflectionProperty($idleStage, 'frameRequested');
    $tick = new ReflectionMethod($idleStage, 'tick');

    $running->setValue($idleStage, true);
    $fullRedraw->setValue($idleStage, false);
    $frameRequested->setValue($idleStage, false);

    MemoryCacheProbe::$calls = 0;
    $tick->invoke($idleStage);

    assertTrue(
        MemoryCacheProbe::$calls === 0,
        'natural memory policy does not flush allocator caches during idle ticks',
    );

    $idleStage = stageFor(flushMemoryCaches: true);
    $running = new ReflectionProperty($idleStage, 'running');
    $fullRedraw = new ReflectionProperty($idleStage, 'fullRedraw');
    $frameRequested = new ReflectionProperty($idleStage, 'frameRequested');
    $tick = new ReflectionMethod($idleStage, 'tick');

    $running->setValue($idleStage, true);
    $fullRedraw->setValue($idleStage, false);
    $frameRequested->setValue($idleStage, false);

    MemoryCacheProbe::$calls = 0;
    $tick->invoke($idleStage);

    assertTrue(MemoryCacheProbe::$calls === 1, 'cache-flush policy preserves idle allocator cache flushing');

    fwrite(STDOUT, "TH-C5 memory policy claim passed\n");
}

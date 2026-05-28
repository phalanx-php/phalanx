#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\DevTools\AegisRuntimeStoreProjector;
use Phalanx\Theatron\DevTools\DevToolsPanel;
use Phalanx\Theatron\DevTools\RuntimeMetricsSlice;
use Phalanx\Theatron\DevTools\RuntimeScopeSlice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Store\StoreWriter;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Theatron;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function renderedLines(Renderable $renderable, int $height = 3): string
{
    $buffer = Buffer::empty(100, $height);
    Painter::paint($renderable, new PaintContext(Rect::sized(100, $height), $buffer));
    $lines = [];

    for ($y = 0; $y < $height; $y++) {
        $line = '';
        for ($x = 0; $x < 100; $x++) {
            $line .= $buffer->get($x, $y)->char;
        }
        $lines[] = rtrim($line);
    }

    return implode("\n", $lines);
}

$host = Application::starting()->compile();
$host->run(static function (ExecutionScope $scope) use ($host): void {
    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'devtools-store',
        RuntimeMetricsSlice::class,
        RuntimeScopeSlice::class,
    ));
    $registry->start($scope);

    $projector = new AegisRuntimeStoreProjector($host->runtime(), $registry->writer());
    $projector->project($scope, 7);

    $lens = $registry->lens();
    $metrics = $lens->handle(RuntimeMetricsSlice::class)->value;
    $scopeState = $lens->handle(RuntimeScopeSlice::class)->value;

    assertTrue($metrics->frames === 7, 'runtime projector writes the current frame count');
    assertTrue($metrics->handles >= 1, 'runtime projector writes live handle count');
    assertTrue($scopeState->activeScopes >= 1, 'runtime projector writes live scope count');

    $mount = new MountedComponent(new DevToolsPanel(), $scope, $lens);

    try {
        $text = renderedLines($mount->render());

        assertTrue(str_contains($text, 'Theatron DevTools'), 'DevTools panel renders from store slices');
        assertTrue(str_contains($text, 'Frames 7'), 'DevTools panel renders projected metrics');
    } finally {
        $mount->dispose();
    }

    $app = Theatron::starting()
        ->root(new DevToolsPanel())
        ->devtools()
        ->build();

    $devtoolsRegistry = $app->createRegistry();
    $devtoolsRegistry->start($scope);
    $devtoolsLens = $devtoolsRegistry->lens();

    assertTrue(
        $devtoolsLens->handle(RuntimeMetricsSlice::class)->value instanceof RuntimeMetricsSlice,
        'Theatron devtools app registers runtime metrics slice',
    );
    assertTrue(
        $devtoolsLens->handle(RuntimeScopeSlice::class)->value instanceof RuntimeScopeSlice,
        'Theatron devtools app registers runtime scope slice',
    );
});

Theatron::starting()
    ->root(new DevToolsPanel())
    ->devtools()
    ->build()
    ->host()
    ->run(static function (ExecutionScope $scope): void {
        $metrics = $scope->service(StoreWriter::class)->update(
            RuntimeMetricsSlice::class,
            static fn(RuntimeMetricsSlice $slice): RuntimeMetricsSlice => new RuntimeMetricsSlice(
                frames: $slice->frames + 1,
                handles: $slice->handles,
                tasks: $slice->tasks,
                events: $slice->events,
            ),
        );

        assertTrue($metrics->frames === 1, 'DevTools Theatron host starts its scoped runtime store services');
    });

fwrite(STDOUT, "TH-A3 store devtools projection claim passed\n");

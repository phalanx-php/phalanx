#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreWriter;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\TheatronServiceBundle;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function textOf(Renderable $renderable): string
{
    $buffer = Buffer::empty(80, 1);
    Painter::paint($renderable, new PaintContext(Rect::sized(80, 1), $buffer));
    $text = '';

    for ($x = 0; $x < 80; $x++) {
        $text .= $buffer->get($x, 0)->char;
    }

    return rtrim($text);
}

final class StoreContextSlice implements Slice
{
    public string $key {
        get => 'store.context';
    }

    public function __construct(
        private(set) int $value = 10,
    ) {
    }
}

final class StoreContextComponent implements StatefulComponent
{
    public bool $scopeAvailable {
        get => $this->hasScope;
    }

    private bool $hasScope = false;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->hasScope = $ctx->scope instanceof ExecutionScope;
        $signal = $ctx->signal('sig');
        $slice = $ctx->lens(StoreContextSlice::class)->value;

        return $ctx->ui->text(sprintf('%s:%d', $signal->value, $slice->value));
    }
}

final class StoreScopeWriterComponent implements StatefulComponent
{
    public bool $wroteThroughScope {
        get => $this->wrote;
    }

    public int $renders {
        get => $this->rendered;
    }

    private bool $wrote = false;
    private int $rendered = 0;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->rendered++;
        $slice = $ctx->lens(StoreContextSlice::class)->value;

        if ($slice->value === 10) {
            $ctx->scope->service(StoreWriter::class)->update(
                StoreContextSlice::class,
                static fn(StoreContextSlice $current): StoreContextSlice => new StoreContextSlice($current->value + 32),
            );
            $this->wrote = true;
        }

        if ($slice->value === 42) {
            $ctx->scope->cancellation()->cancel();
        }

        return $ctx->ui->text(sprintf('service:%d', $slice->value));
    }
}

Application::starting()->compile()->run(static function (ExecutionScope $scope): void {
    $component = new StoreContextComponent();
    $app = Theatron::starting()
        ->root($component)
        ->store(Store::concurrent('ctx-store', StoreContextSlice::class))
        ->build();

    $mount = $app->mount($scope);

    try {
        assertTrue(textOf($mount->render()) === 'sig:10', 'Theatron builder mounts root with signal and store context');
        assertTrue($component->scopeAvailable, 'StatefulContext exposes the current ExecutionScope');

        $mount->state->lens(StoreContextSlice::class)->value = new StoreContextSlice(11);
        assertTrue($mount->renderRequests === 1, 'builder-provided store lens dirties mounted root');
        assertTrue($mount->consumeDirty(), 'builder-provided dirty batch is consumable');
        assertTrue(textOf($mount->render()) === 'sig:11', 'builder-provided store lens re-renders updated value');

        $host = $app->host();
        $hasTheatronProvider = array_any(
            $host->providers(),
            static fn(object $provider): bool => $provider instanceof TheatronServiceBundle,
        );
        assertTrue($hasTheatronProvider, 'Theatron host carries its service bundle');
    } finally {
        $mount->dispose();
    }
});

$serviceApp = Theatron::starting()
    ->root(new StoreContextComponent())
    ->store(Store::concurrent('ctx-service-store', StoreContextSlice::class))
    ->build();

$serviceApp->host()->run(static function (ExecutionScope $scope): void {
    $writer = $scope->service(StoreWriter::class);
    $updated = $writer->update(
        StoreContextSlice::class,
        static fn(StoreContextSlice $slice): StoreContextSlice => new StoreContextSlice($slice->value + 1),
    );

    assertTrue($updated->value === 11, 'Theatron service bundle starts scoped store registry before writer use');
});

$scopeWriter = new StoreScopeWriterComponent();
$runnerApp = Theatron::starting()
    ->root($scopeWriter)
    ->store(Store::concurrent('ctx-runner-store', StoreContextSlice::class))
    ->build();

$stream = fopen('php://memory', 'w+');
assertTrue($stream !== false, 'memory stream opens for Theatron runner claim');

$runnerApp->host()->run(static function (ExecutionScope $scope) use ($runnerApp, $stream): void {
    $runnerApp->run($scope, new StageConfig(
        screenMode: ScreenMode::Inline,
        bracketedPaste: false,
        activeIntervalUs: 1_000,
        stream: $stream,
        flushMemoryCaches: false,
    ));
});

fclose($stream);
assertTrue($scopeWriter->wroteThroughScope, 'Theatron runner root writes through scoped StoreWriter service');
assertTrue($scopeWriter->renders >= 2, 'Theatron runner root sees scoped StoreWriter update through the same lens');

fwrite(STDOUT, "TH-A2 context builder store claim passed\n");

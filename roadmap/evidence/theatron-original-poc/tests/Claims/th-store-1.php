#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreException;
use Phalanx\Theatron\Store\StoreHandle;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Store\UnsupportedSliceSchema;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function paintedText(Renderable $renderable): string
{
    $buffer = Buffer::empty(80, 1);
    Painter::paint($renderable, new PaintContext(Rect::sized(80, 1), $buffer));
    $text = '';

    for ($x = 0; $x < 80; $x++) {
        $text .= $buffer->get($x, 0)->char;
    }

    return rtrim($text);
}

final class StoreClaimCounterSlice implements Slice
{
    public string $key {
        get => 'store.claim.counter';
    }

    public function __construct(
        private(set) int $count = 0,
        private(set) string $label = 'ready',
    ) {
    }
}

final class StoreClaimNullableSlice implements Slice
{
    public string $key {
        get => 'store.claim.nullable';
    }

    public function __construct(
        private(set) ?string $value = null,
    ) {
    }
}

final class StoreClaimBadSlice implements Slice
{
    public string $key {
        get => 'store.claim.bad';
    }

    /** @param list<string> $items */
    public function __construct(
        private(set) array $items = [],
    ) {
    }
}

final class StoreClaimMissingPropertySlice implements Slice
{
    public string $key {
        get => 'store.claim.missing-property';
    }

    public function __construct(
        int $count = 0,
    ) {
    }
}

final class StoreClaimLongStringSlice implements Slice
{
    public string $key {
        get => 'store.claim.long-string';
    }

    public function __construct(
        private(set) string $value = '',
    ) {
    }
}

final class StoreClaimReader implements StatefulComponent
{
    /** @var StoreHandle<StoreClaimCounterSlice>|null */
    public ?StoreHandle $handle = null;

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->handle = $ctx->lens(StoreClaimCounterSlice::class);
        $slice = $this->handle->value;

        return $ctx->ui->text(sprintf('%s:%d', $slice->label, $slice->count));
    }
}

Application::starting()->compile()->run(static function (ExecutionScope $scope): void {
    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'store-claim',
        StoreClaimCounterSlice::class,
        StoreClaimNullableSlice::class,
    ));
    $registry->start($scope);

    $lens = new Lens($registry);
    $reader = new StoreClaimReader();
    $mount = new MountedComponent($reader, $scope, $lens);

    assertTrue(paintedText($mount->render()) === 'ready:0', 'store lens reads seeded scalar slice state');
    assertTrue($mount->renderRequests === 0, 'initial lens read does not dirty the component');

    $updated = $reader->handle?->update(
        static fn(StoreClaimCounterSlice $slice): StoreClaimCounterSlice => new StoreClaimCounterSlice(
            count: $slice->count + 1,
            label: 'updated',
        ),
    );

    assertTrue($updated instanceof StoreClaimCounterSlice, 'store update returns the hydrated slice type');
    assertTrue($updated->count === 1, 'store update mutates through the writer coroutine');
    assertTrue($mount->renderRequests === 1, 'store subscription dirties subscribed components');
    assertTrue($mount->consumeDirty(), 'store dirty batch is consumable');
    assertTrue(paintedText($mount->render()) === 'updated:1', 'store lens re-render reads shared state');

    $requestsBeforeNoop = $mount->renderRequests;
    $reader->handle?->update(static fn(StoreClaimCounterSlice $slice): StoreClaimCounterSlice => $slice);
    assertTrue($mount->renderRequests === $requestsBeforeNoop, 'no-op store writes do not dirty subscribers');

    try {
        $reader->handle?->subscribe(function (): void {
        });
        assertTrue(false, 'non-static store subscribers are rejected');
    } catch (StoreException) {
    }

    $nestedWriteRejected = false;
    $nestedSubscription = $reader->handle?->subscribe(static function () use ($reader, &$nestedWriteRejected): void {
        try {
            $reader->handle?->update(
                static fn(StoreClaimCounterSlice $slice): StoreClaimCounterSlice => new StoreClaimCounterSlice(
                    count: $slice->count + 1,
                    label: 'nested',
                ),
            );
        } catch (StoreException) {
            $nestedWriteRejected = true;
        }
    });
    $reader->handle?->update(
        static fn(StoreClaimCounterSlice $slice): StoreClaimCounterSlice => new StoreClaimCounterSlice(
            count: $slice->count + 1,
            label: 'subscriber',
        ),
    );
    $nestedSubscription?->dispose();
    assertTrue($nestedWriteRejected, 'store rejects nested writes from subscriber callbacks instead of timing out');

    $nullable = $lens->handle(StoreClaimNullableSlice::class);
    assertTrue($nullable->value->value === null, 'nullable string slice starts as null');
    $nullable->value = new StoreClaimNullableSlice('present');
    assertTrue($nullable->value->value === 'present', 'nullable string slice stores present value');
    $nullable->value = new StoreClaimNullableSlice();
    assertTrue($nullable->value->value === null, 'nullable string slice stores absent value');

    $slow = $reader->handle?->update(static function (StoreClaimCounterSlice $slice): StoreClaimCounterSlice {
        usleep(1_100_000);

        return new StoreClaimCounterSlice(
            count: $slice->count + 1,
            label: 'slow',
        );
    });
    assertTrue($slow?->label === 'slow', 'queued store writes wait for acknowledgement without false timeout failures');

    $requestsBeforeDispose = $mount->renderRequests;
    $mount->dispose();
    $reader->handle?->update(
        static fn(StoreClaimCounterSlice $slice): StoreClaimCounterSlice => new StoreClaimCounterSlice(
            count: $slice->count + 1,
            label: 'after-dispose',
        ),
    );
    assertTrue($mount->renderRequests === $requestsBeforeDispose, 'disposed store subscription no longer dirties the mount');

    try {
        StoreRegistry::fromDefinitions(Store::concurrent('bad-store', StoreClaimBadSlice::class));
        assertTrue(false, 'array slice properties are rejected');
    } catch (UnsupportedSliceSchema) {
    }

    try {
        StoreRegistry::fromDefinitions(Store::concurrent('missing-property-store', StoreClaimMissingPropertySlice::class));
        assertTrue(false, 'constructor parameters without readable properties are rejected');
    } catch (UnsupportedSliceSchema) {
    }

    $longStringRegistry = StoreRegistry::fromDefinitions(Store::concurrent(
        'long-string-store',
        StoreClaimLongStringSlice::class,
    ));
    $longStringRegistry->start($scope);
    $longString = $longStringRegistry->lens()->handle(StoreClaimLongStringSlice::class);

    try {
        $longString->value = new StoreClaimLongStringSlice(str_repeat('x', 2049));
        assertTrue(false, 'oversized string slice values are rejected before Swoole Table truncation');
    } catch (StoreException) {
    }
});

fwrite(STDOUT, "TH-A1 store scalar table claim passed\n");

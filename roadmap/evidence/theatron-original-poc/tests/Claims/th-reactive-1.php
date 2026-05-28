#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Reactive\Computed;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\Resource;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\Sync;
use Phalanx\Theatron\Reactive\Watch;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ============================
// 1. Computed: basic behavior
// ============================

$count = new Signal(5);
$runCount = 0;

$double = new Computed(static function () use ($count, &$runCount): int {
    $runCount++;

    return $count->value * 2;
});

assertTrue($runCount === 0, 'computed factory not called before first read');

$v1 = $double->value;
assertTrue($v1 === 10, 'computed returns derived value');
assertTrue($runCount === 1, 'factory runs on first read');

$v2 = $double->value;
assertTrue($v2 === 10, 'computed caches on second read');
assertTrue($runCount === 1, 'factory does not re-run when clean');

$count->value = 7;
$v3 = $double->value;
assertTrue($v3 === 14, 'computed recomputes when dep changes');
assertTrue($runCount === 2, 'factory re-ran exactly once');

// ============================
// 2. Computed chain propagation
// ============================

$a = new Signal(3);

$b = new Computed(static function () use ($a): int {
    return $a->value + 1;
});

$c = new Computed(static function () use ($b): int {
    return $b->value * 10;
});

assertTrue($c->value === 40, 'chain: A=3 → B=4 → C=40');

$a->value = 5;
assertTrue($c->value === 60, 'chain: A=5 → B=6 → C=60 propagates through');

// ============================
// 3. Watch behavior
// ============================

$source = new Signal('alpha');
$watchLog = [];

$watch = new Watch(
    static function () use ($source): string {
        return $source->value;
    },
    static function (mixed $new, mixed $old) use (&$watchLog): void {
        $watchLog[] = "{$old}->{$new}";
    },
);

assertTrue($watchLog === [], 'watch does not fire on creation');

$source->value = 'beta';
assertTrue($watchLog === ['alpha->beta'], 'watch fires with old/new on dep change');

$source->value = 'beta';
assertTrue(count($watchLog) === 1, 'watch does not fire on same-value write');

$source->value = 'gamma';
assertTrue($watchLog === ['alpha->beta', 'beta->gamma'], 'watch fires again on new value');

$watch->dispose();
$source->value = 'delta';
assertTrue(count($watchLog) === 2, 'watch does not fire after disposal');

// ============================
// 4. Sync lifecycle
// ============================

$syncLog = [];

$sync = new Sync(
    static function () use (&$syncLog): ?callable {
        $syncLog[] = 'setup';

        return static function () use (&$syncLog): void {
            $syncLog[] = 'cleanup';
        };
    },
);

assertTrue($syncLog === ['setup'], 'sync runs setup on construction');

$sync->update('key-2');
assertTrue($syncLog === ['setup', 'cleanup', 'setup'], 'sync runs cleanup+setup on key change');

$sync->update('key-2');
assertTrue(count($syncLog) === 3, 'sync skips when key unchanged');

$sync->dispose();
assertTrue($syncLog === ['setup', 'cleanup', 'setup', 'cleanup'], 'sync runs cleanup on disposal');

$sync->dispose();
assertTrue(count($syncLog) === 4, 'sync double-disposal is idempotent');

// ============================
// 5. Resource state machine
// ============================

$resource = new Resource(
    static fn(mixed $key): string => "result-{$key}",
);

assertTrue(!$resource->ok, 'resource starts not ok');
assertTrue($resource->value === null, 'resource starts with null value');
assertTrue($resource->error === null, 'resource starts with null error');
assertTrue(!$resource->loading, 'resource starts not loading');

$resource->refresh('key1');
assertTrue($resource->ok, 'resource ok after sync fetch');
assertTrue($resource->value === 'result-key1', 'resource has correct value');
assertTrue(!$resource->loading, 'resource not loading after completion');

$resource->refresh('key2');
assertTrue($resource->value === 'result-key2', 'resource value updates on refresh');

$failResource = new Resource(
    static function (mixed $key): never {
        throw new RuntimeException("fail-{$key}");
    },
);

$failResource->refresh('bad');
assertTrue($failResource->error instanceof RuntimeException, 'resource captures error');
assertTrue($failResource->error->getMessage() === 'fail-bad', 'resource error has message');
assertTrue(!$failResource->loading, 'resource not loading after error');
assertTrue($failResource->value === null, 'resource value stays null after error');

$failResource->dispose();
$failResource->refresh('after-dispose');
assertTrue($failResource->error->getMessage() === 'fail-bad', 'resource ignores refresh after disposal');

// ============================
// 6. Disposal ordering via StatefulContext
// ============================

$disposeOrder = [];
$dirty = new DirtyBatch();

$ctx = new Phalanx\Theatron\Component\StatefulContext($dirty);

$sig = $ctx->signal('x');

$comp = $ctx->computed(static function () use ($sig): string {
    return $sig->value . '!';
});

$watch = $ctx->watch(
    static function () use ($sig): string {
        return $sig->value;
    },
    static function (mixed $new, mixed $old) use (&$disposeOrder): void {
        $disposeOrder[] = 'watch-effect';
    },
);

$ctx->onDispose(static function () use (&$disposeOrder): void {
    $disposeOrder[] = 'user';
});

$ctx->dispose();
assertTrue(in_array('user', $disposeOrder, true), 'user onDispose callback runs');
assertTrue($sig->isDisposed, 'signal disposed during context disposal');
assertTrue($comp->isDisposed, 'computed disposed during context disposal');

assertTrue(!in_array('watch-effect', $disposeOrder, true), 'disposed watch does not fire during disposal');

// --- Watch disposal standalone ---

$watchSig = new Signal('one');
$watchFired = false;

$standaloneWatch = new Watch(
    static function () use ($watchSig): string {
        return $watchSig->value;
    },
    static function (mixed $new, mixed $old) use (&$watchFired): void {
        $watchFired = true;
    },
);

$standaloneWatch->dispose();
$watchSig->value = 'two';
assertTrue(!$watchFired, 'disposed watch does not fire when dep changes');

// --- Sync standalone lifecycle ---

$syncLog = [];

$syncObj = new Sync(static function () use (&$syncLog): ?callable {
    $syncLog[] = 'setup';

    return static function () use (&$syncLog): void {
        $syncLog[] = 'cleanup';
    };
});

$syncObj->dispose();
assertTrue($syncLog === ['setup', 'cleanup'], 'sync cleanup runs on dispose');

// ============================
// 7. Watch on Computed
// ============================

$base = new Signal(10);

$derived = new Computed(static function () use ($base): int {
    return $base->value * 3;
});

$watchedValues = [];

$compWatch = new Watch(
    static function () use ($derived): int {
        return $derived->value;
    },
    static function (mixed $new, mixed $old) use (&$watchedValues): void {
        $watchedValues[] = "{$old}->{$new}";
    },
);

$base->value = 20;
assertTrue($watchedValues === ['30->60'], 'watch on computed fires when underlying signal changes');
$compWatch->dispose();

// ============================
// 8. Computed factory throws
// ============================

$throwCount = 0;
$shouldThrow = true;

$fragile = new Computed(static function () use (&$throwCount, &$shouldThrow): int {
    $throwCount++;

    if ($shouldThrow) {
        throw new RuntimeException('factory error');
    }

    return 42;
});

$caught = false;

try {
    $fragile->value;
} catch (RuntimeException $e) {
    $caught = true;
    assertTrue($e->getMessage() === 'factory error', 'computed propagates factory exception');
}

assertTrue($caught, 'computed factory exception is catchable');
assertTrue($throwCount === 1, 'factory ran once before throwing');

$shouldThrow = false;
$val = $fragile->value;
assertTrue($val === 42, 'computed recovers after factory stops throwing');
assertTrue($throwCount === 2, 'factory re-ran on next read after error');

// ============================
// 9. Computed circular dependency detection
// ============================

$circularCaught = false;

try {
    $circular = new Computed(static function () use (&$circular): int {
        return $circular->value + 1;
    });
    $circular->value;
} catch (RuntimeException $e) {
    $circularCaught = true;
    assertTrue(str_contains($e->getMessage(), 'Circular'), 'circular computed throws descriptive error');
}

assertTrue($circularCaught, 'circular computed dependency is detected');

// ============================
// 10. Diamond dependency
// ============================

$diamond = new Signal(1);
$dRunCount = 0;

$left = new Computed(static function () use ($diamond): int {
    return $diamond->value + 1;
});

$right = new Computed(static function () use ($diamond): int {
    return $diamond->value * 2;
});

$bottom = new Computed(static function () use ($left, $right, &$dRunCount): int {
    $dRunCount++;

    return $left->value + $right->value;
});

assertTrue($bottom->value === 4, 'diamond: 1+1 + 1*2 = 4');
$dRunCount = 0;

$diamond->value = 3;
assertTrue($bottom->value === 10, 'diamond: 3+1 + 3*2 = 10');
assertTrue($dRunCount === 1, 'diamond bottom recomputes exactly once');

// ============================
// 11. Resource ok persists through failure
// ============================

$callCount = 0;

$resilient = new Resource(static function (mixed $key) use (&$callCount): string {
    $callCount++;

    if ($key === 'bad') {
        throw new RuntimeException('fetch failed');
    }

    return "data-{$key}";
});

$resilient->refresh('good');
assertTrue($resilient->ok, 'resource ok after success');
assertTrue($resilient->value === 'data-good', 'resource has good value');

$resilient->refresh('bad');
assertTrue($resilient->ok, 'resource ok persists through failure');
assertTrue($resilient->value === 'data-good', 'resource value preserved through failure');
assertTrue($resilient->error instanceof RuntimeException, 'resource error set after failure');

// ============================
// 12. Sync with null cleanup
// ============================

$nullCleanupLog = [];

$nullSync = new Sync(static function () use (&$nullCleanupLog): ?callable {
    $nullCleanupLog[] = 'setup';

    return null;
});

assertTrue($nullCleanupLog === ['setup'], 'sync with null cleanup runs setup');

$nullSync->update('new-key');
assertTrue($nullCleanupLog === ['setup', 'setup'], 'sync with null cleanup re-runs setup on key change');

$nullSync->dispose();
assertTrue($nullCleanupLog === ['setup', 'setup'], 'sync with null cleanup disposes cleanly');

// ============================
// 13. Watch re-entrancy guard
// ============================

$reentrantSig = new Signal(0);
$reentrantLog = [];

$reentrantWatch = new Watch(
    static function () use ($reentrantSig): int {
        return $reentrantSig->value;
    },
    static function (mixed $new, mixed $old) use ($reentrantSig, &$reentrantLog): void {
        $reentrantLog[] = "{$old}->{$new}";

        if ($new < 3) {
            $reentrantSig->value = $new + 1;
        }
    },
);

$reentrantSig->value = 1;
assertTrue(count($reentrantLog) === 1, 'watch re-entrancy guard prevents recursive effect');
assertTrue($reentrantLog[0] === '0->1', 'watch fires once with correct values');
$reentrantWatch->dispose();

// ============================
// 14. Static closure enforcement
// ============================

$staticErrors = 0;

try {
    new Computed(function (): int { return 1; });
} catch (RuntimeException) {
    $staticErrors++;
}

try {
    new Watch(function (): int { return 1; }, static function (): void {});
} catch (RuntimeException) {
    $staticErrors++;
}

try {
    new Watch(static function (): int { return 1; }, function (): void {});
} catch (RuntimeException) {
    $staticErrors++;
}

try {
    new Resource(function (): string { return 'x'; });
} catch (RuntimeException) {
    $staticErrors++;
}

try {
    new Sync(function (): ?callable { return null; });
} catch (RuntimeException) {
    $staticErrors++;
}

assertTrue($staticErrors === 5, 'all reactive primitives reject non-static closures');

// ============================
// 15. StatefulContext slot caching
// ============================

$slotDirty = new DirtyBatch();
$slotCtx = new Phalanx\Theatron\Component\StatefulContext($slotDirty);

$sig1 = $slotCtx->signal('a');
$sig2Again = $slotCtx->signal('b');

$slotCtx->beginRender();

$sig1Again = $slotCtx->signal('a');
$sig2Again2 = $slotCtx->signal('b');

assertTrue($sig1 === $sig1Again, 'signal slot cache returns same instance after beginRender');
assertTrue($sig2Again === $sig2Again2, 'second signal slot also cached');

$comp1 = $slotCtx->computed(static function () use ($sig1): string {
    return $sig1->value . '!';
});

$slotCtx->beginRender();

$comp1Again = $slotCtx->computed(static function () use ($sig1): string {
    return $sig1->value . '!';
});

assertTrue($comp1 === $comp1Again, 'computed slot cache returns same instance');

$slotCtx->dispose();

fwrite(STDOUT, "TH-B.03 reactive primitives claim passed\n");

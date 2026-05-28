#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountedPureComponent;
use Phalanx\Theatron\Component\PureComponent;
use Phalanx\Theatron\Component\PureContext;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Reactive\Computed;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ============================
// A. StatefulContext unified surface
// ============================

$dirty = new DirtyBatch();
$ctx = new StatefulContext($dirty);

assertTrue(property_exists(StatefulContext::class, 'scope'), 'StatefulContext exposes scope as property');
assertTrue($ctx->ui instanceof Ui, 'StatefulContext provides Ui');

$sig = $ctx->signal('hello');
assertTrue($sig instanceof Signal, 'signal() returns Signal');

$comp = $ctx->computed(static function () use ($sig): string {
    return $sig->value . '!';
});
assertTrue($comp instanceof Computed, 'computed() returns Computed');
assertTrue($comp->value === 'hello!', 'computed derives from signal');

$sig->value = 'world';
assertTrue($comp->value === 'world!', 'computed updates when signal changes');
assertTrue($dirty->isDirty, 'signal change triggers DirtyBatch');
$dirty->consume();

$ctx->dispose();

// ============================
// B. PureContext unified surface
// ============================

assertTrue(property_exists(PureContext::class, 'scope'), 'PureContext exposes scope as property');
assertTrue(!method_exists(PureContext::class, 'scope'), 'PureContext scope is not a method');

$pureCtx = new PureContext();
assertTrue($pureCtx->ui instanceof Ui, 'PureContext provides Ui');
assertTrue(!method_exists(PureContext::class, 'signal'), 'PureContext has no signal method');

$scopeProp = new ReflectionProperty(PureContext::class, 'scope');
$scopeType = $scopeProp->getType();
assertTrue($scopeType instanceof ReflectionNamedType, 'scope has named type');
assertTrue($scopeType->getName() === TaskScope::class, 'PureContext scope type is TaskScope');

$pureCtx->dispose();

// ============================
// C. Composition: Stateful parent + Pure child
// ============================

$pureRenderCount = 0;

$pureChild = new class ($pureRenderCount) implements PureComponent {
    public function __construct(private int &$counter)
    {
    }

    public function __invoke(PureContext $ctx): Renderable
    {
        $this->counter++;

        return $ctx->ui->text('pure child');
    }
};

$mount = new MountedPureComponent($pureChild);
$mount->render();
assertTrue($pureRenderCount === 1, 'pure child renders on first call');

$mount->render();
assertTrue($pureRenderCount === 1, 'pure child memoized on second call');

$newPureCount = 0;

$newPure = new class ($newPureCount) implements PureComponent {
    public function __construct(private int &$counter)
    {
    }

    public function __invoke(PureContext $ctx): Renderable
    {
        $this->counter++;

        return $ctx->ui->text('new child');
    }
};

$mount->update($newPure);
$mount->render();
assertTrue($newPureCount === 1, 'new pure child renders after update');

$mount->dispose();

// Verify parent disposal does not crash independent child
$childMount = new MountedPureComponent($pureChild);
$childMount->render();
$childMount->dispose();

// ============================
// D. Cross-primitive composition
// ============================

$crossDirty = new DirtyBatch();
$crossCtx = new StatefulContext($crossDirty);

$base = $crossCtx->signal(10, key: 'base');

$derived = $crossCtx->computed(static function () use ($base): int {
    return $base->value * 3;
}, key: 'derived');

$watchLog = [];

$crossCtx->watch(
    static function () use ($derived): int {
        return $derived->value;
    },
    static function (mixed $new, mixed $old) use (&$watchLog): void {
        $watchLog[] = "{$old}->{$new}";
    },
    key: 'watcher',
);

assertTrue($derived->value === 30, 'computed reads signal in cross-primitive');

$base->value = 20;
assertTrue($derived->value === 60, 'computed updates from signal change');
assertTrue($watchLog === ['30->60'], 'watch fires on computed change from signal');

$crossCtx->dispose();

// ============================
// E. Scope propagation
// ============================

$noScopeCtx = new StatefulContext(new DirtyBatch());
$statefulScopeThrew = false;

try {
    $noScopeCtx->scope;
} catch (RuntimeException) {
    $statefulScopeThrew = true;
}

assertTrue($statefulScopeThrew, 'StatefulContext throws when no scope');
$noScopeCtx->dispose();

$noScopePure = new PureContext();
$pureScopeThrew = false;

try {
    $noScopePure->scope;
} catch (RuntimeException) {
    $pureScopeThrew = true;
}

assertTrue($pureScopeThrew, 'PureContext throws when no scope');
$noScopePure->dispose();

fwrite(STDOUT, "TH-C.01 context convergence claim passed\n");

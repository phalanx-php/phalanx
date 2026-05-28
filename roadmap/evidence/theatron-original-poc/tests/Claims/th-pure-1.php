#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountedPureComponent;
use Phalanx\Theatron\Component\PureComponent;
use Phalanx\Theatron\Component\PureContext;
use Phalanx\Theatron\Tdom\Renderable;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$renderCount = 0;

$component = new class ($renderCount) implements PureComponent {
    public function __construct(private int &$counter)
    {
    }

    public function __invoke(PureContext $ctx): Renderable
    {
        $this->counter++;

        return $ctx->ui->text('rendered');
    }
};

// --- 1. First render calls __invoke ---

$mount = new MountedPureComponent($component);
$mount->render();

assertTrue($renderCount === 1, 'first render calls __invoke');

// --- 2. Second render with same instance — __invoke NOT called ---

$mount->render();

assertTrue($renderCount === 1, 'second render with same instance skips __invoke');

// --- 3. Third render with new instance — __invoke called ---

$newRenderCount = 0;

$newComponent = new class ($newRenderCount) implements PureComponent {
    public function __construct(private int &$counter)
    {
    }

    public function __invoke(PureContext $ctx): Renderable
    {
        $this->counter++;

        return $ctx->ui->text('new render');
    }
};

$mount->update($newComponent);
$mount->render();

assertTrue($newRenderCount === 1, 'render after update with new instance calls __invoke');

// --- 4. isDirty tracks component identity changes ---

$stableMount = new MountedPureComponent($component);
$stableMount->consumeDirty();
$stableMount->render();

$stableMount->update($component);
assertTrue(!$stableMount->isDirty, 'update with same instance leaves isDirty false');

$stableMount->update($newComponent);
assertTrue($stableMount->isDirty, 'update with different instance sets isDirty true');

// --- 5. PureContext dispose runs LIFO callbacks ---

$order = [];

$ctx = new PureContext();
$ctx->onDispose(static function () use (&$order): void {
    $order[] = 'first';
});
$ctx->onDispose(static function () use (&$order): void {
    $order[] = 'second';
});
$ctx->dispose();

assertTrue($order === ['second', 'first'], 'dispose callbacks run LIFO');

$ctx->onDispose(static function () use (&$order): void {
    $order[] = 'after-dispose';
});
assertTrue($order === ['second', 'first', 'after-dispose'], 'onDispose after disposal fires immediately');

// --- 6. PureContext has no signal method ---

assertTrue(!method_exists(PureContext::class, 'signal'), 'PureContext has no signal method');

// --- 7. PureContext scope is a property hook ---

assertTrue(property_exists(PureContext::class, 'scope'), 'PureContext exposes scope as property');
assertTrue(!method_exists(PureContext::class, 'scope'), 'PureContext scope is not a method');

$scopeProp = new ReflectionProperty(PureContext::class, 'scope');
$scopeType = $scopeProp->getType();
assertTrue($scopeType instanceof ReflectionNamedType, 'PureContext scope property has named type');
assertTrue($scopeType->getName() === TaskScope::class, 'PureContext scope property type is TaskScope');

$noScopeCtx = new PureContext();
$scopeThrew = false;

try {
    $noScopeCtx->scope;
} catch (RuntimeException $e) {
    $scopeThrew = true;
}

assertTrue($scopeThrew, 'PureContext scope throws without TaskScope');
$noScopeCtx->dispose();

fwrite(STDOUT, "TH-B.02 pure component claim passed\n");

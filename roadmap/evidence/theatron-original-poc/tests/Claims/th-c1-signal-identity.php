#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Scope\Disposable;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
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

class ProofComponent implements StatefulComponent
{
    private(set) ?Signal $count = null;
    private(set) ?Signal $label = null;

    /** @var list<int> */
    private(set) array $countIdentities = [];

    /** @var list<int> */
    private(set) array $labelIdentities = [];

    public function __invoke(StatefulContext $ctx): Renderable
    {
        $this->count = $ctx->signal(0);
        $this->label = $ctx->signal('ready');

        $this->countIdentities[] = spl_object_id($this->count);
        $this->labelIdentities[] = spl_object_id($this->label);

        return new Ui()->text('');
    }
}

$component = new ProofComponent();
$mount = new MountedComponent($component);

for ($i = 0; $i < 100; $i++) {
    $mount->render();
}

assertTrue(count(array_unique($component->countIdentities)) === 1, 'first signal slot is stable across renders');
assertTrue(count(array_unique($component->labelIdentities)) === 1, 'second signal slot is stable across renders');
assertTrue($mount->state->signalCount === 2, 'context owns two stable signal slots');
assertTrue($mount->state->subscriptionCount === 2, 'context owns two dirty subscriptions, one per signal slot');
assertTrue($mount->state instanceof Disposable, 'stateful context implements Disposable');

$count = $component->count;
$label = $component->label;

assertTrue($count instanceof Signal, 'count signal exists after render');
assertTrue($label instanceof Signal, 'label signal exists after render');
assertTrue($mount->renderRequests === 0, 'render request count starts clean');
assertTrue(!$mount->isDirty, 'dirty batch starts clean');

$count->value = 1;
$count->value = 2;
$label->value = 'changed';

assertTrue($mount->renderRequests === 1, 'multiple signal writes coalesce into one render request');
assertTrue($mount->isDirty, 'signal writes mark the mounted component dirty');
assertTrue($mount->consumeDirty(), 'dirty batch is consumable at the render boundary');
assertTrue(!$mount->isDirty, 'dirty batch is clean after consumption');

$count->value = 2;
assertTrue($mount->renderRequests === 1, 'unchanged signal writes do not request renders');

$label->value = 'changed again';
assertTrue($mount->renderRequests === 2, 'new batch requests one additional render');

$disposeLog = [];
$mount->state->onDispose(static function () use (&$disposeLog): void {
    $disposeLog[] = 'first';
});
$mount->state->onDispose(static function () use (&$disposeLog): void {
    $disposeLog[] = 'second';
});

$failed = false;

try {
    $count->subscribe(function (): void {
    });
} catch (RuntimeException) {
    $failed = true;
}

assertTrue($failed, 'signal subscribers must be static closures');

$mount->dispose();

assertTrue($disposeLog === ['second', 'first'], 'stateful context runs dispose callbacks in LIFO order');
assertTrue($count->subscriberCount === 0, 'count signal subscribers are cleared on dispose');
assertTrue($label->subscriberCount === 0, 'label signal subscribers are cleared on dispose');
assertTrue($count->isDisposed, 'count signal is marked disposed');
assertTrue($label->isDisposed, 'label signal is marked disposed');
assertTrue($mount->state->signalCount === 0, 'context releases signal slots on dispose');
assertTrue($mount->state->subscriptionCount === 0, 'context releases subscriptions on dispose');

$mount->state->onDispose(static function () use (&$disposeLog): void {
    $disposeLog[] = 'late';
});
assertTrue($disposeLog === ['second', 'first', 'late'], 'stateful context runs late dispose callbacks immediately');

$failed = false;

try {
    $count->value = 3;
} catch (RuntimeException) {
    $failed = true;
}

assertTrue($failed, 'disposed signal writes fail loudly');

fwrite(STDOUT, "TH-C1 signal identity claim passed\n");

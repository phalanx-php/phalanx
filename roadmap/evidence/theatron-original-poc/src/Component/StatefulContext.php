<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Closure;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\DevTools\SignalRegistry;
use Phalanx\Theatron\Reactive\Computed;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\Resource;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\SignalSubscription;
use Phalanx\Theatron\Reactive\Sync;
use Phalanx\Theatron\Reactive\Watch;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\StoreHandle;
use Phalanx\Theatron\Store\StoreSubscription;
use Phalanx\Theatron\Tdom\Ui;
use RuntimeException;
use Throwable;

final class StatefulContext implements Disposable
{
    private bool $disposed = false;
    private int $slot = 0;
    private int $computedSlot = 0;
    private int $watchSlot = 0;
    private int $syncSlot = 0;
    private int $resourceSlot = 0;

    /** @var array<string, Signal> */
    private array $signals = [];

    /** @var list<SignalSubscription> */
    private array $subscriptions = [];

    /** @var array<class-string<Slice>, StoreHandle> */
    private array $handles = [];

    /** @var list<StoreSubscription> */
    private array $storeSubscriptions = [];

    /** @var array<string, Computed> */
    private array $computeds = [];

    /** @var array<string, Watch> */
    private array $watches = [];

    /** @var array<string, Sync> */
    private array $syncs = [];

    /** @var array<string, Resource> */
    private array $resources = [];

    /** @var array<string, mixed> */
    private array $resourceKeys = [];

    /** @var list<Closure(): void> */
    private array $disposeStack = [];

    public ExecutionScope $scope {
        get {
            $this->assertNotDisposed();

            return $this->executionScope ?? throw new RuntimeException('Stateful context has no execution scope.');
        }
    }

    public int $signalCount {
        get => count($this->signals);
    }

    public int $subscriptionCount {
        get => count(array_filter(
            $this->subscriptions,
            static fn(SignalSubscription $subscription): bool => !$subscription->isDisposed,
        ));
    }

    private(set) Ui $ui;

    public function __construct(
        private readonly DirtyBatch $dirty,
        private readonly ?ExecutionScope $executionScope = null,
        private readonly ?Lens $lens = null,
    ) {
        $this->ui = new Ui();
    }

    public function beginRender(): void
    {
        $this->assertNotDisposed();
        $this->slot = 0;
        $this->computedSlot = 0;
        $this->watchSlot = 0;
        $this->syncSlot = 0;
        $this->resourceSlot = 0;
    }

    public function signal(mixed $initial, ?string $key = null): Signal
    {
        $this->assertNotDisposed();

        $slot = $key ?? (string) $this->slot++;

        if (isset($this->signals[$slot])) {
            return $this->signals[$slot];
        }

        $dirty = $this->dirty;
        $signal = new Signal($initial);
        $this->signals[$slot] = $signal;
        $this->subscriptions[] = $signal->subscribe(static fn() => $dirty->request());

        SignalRegistry::register($signal, $slot);

        return $signal;
    }

    /**
     * @template T of Slice
     * @param class-string<T> $slice
     * @return StoreHandle<T>
     */
    public function lens(string $slice): StoreHandle
    {
        $this->assertNotDisposed();

        if (isset($this->handles[$slice])) {
            return $this->handles[$slice];
        }

        $lens = $this->lens ?? throw new RuntimeException('Stateful context has no Store lens.');
        $dirty = $this->dirty;
        $handle = $lens->handle($slice);
        $this->handles[$slice] = $handle;
        $this->storeSubscriptions[] = $handle->subscribe(static fn() => $dirty->request());

        return $handle;
    }

    public function computed(Closure $factory, ?string $key = null): Computed
    {
        $this->assertNotDisposed();

        $slot = $key ?? 'c' . $this->computedSlot++;

        if (isset($this->computeds[$slot])) {
            return $this->computeds[$slot];
        }

        $dirty = $this->dirty;
        $computed = new Computed($factory, static fn() => $dirty->request());
        $this->computeds[$slot] = $computed;

        return $computed;
    }

    public function watch(Closure $selector, Closure $effect, ?string $key = null): Watch
    {
        $this->assertNotDisposed();

        $slot = $key ?? 'w' . $this->watchSlot++;

        if (isset($this->watches[$slot])) {
            return $this->watches[$slot];
        }

        $watch = new Watch($selector, $effect);
        $this->watches[$slot] = $watch;

        return $watch;
    }

    public function sync(Closure $setup, mixed $key = null): Sync
    {
        $this->assertNotDisposed();

        $slot = 's' . $this->syncSlot++;

        if (isset($this->syncs[$slot])) {
            $this->syncs[$slot]->update($key);

            return $this->syncs[$slot];
        }

        $scope = $this->executionScope ?? throw new RuntimeException('Sync requires an execution scope.');
        $sync = new Sync($setup, $scope, $key);
        $this->syncs[$slot] = $sync;

        return $sync;
    }

    public function resource(Closure $fetcher, mixed $key = null): Resource
    {
        $this->assertNotDisposed();

        $slot = 'r' . $this->resourceSlot++;

        if (isset($this->resources[$slot])) {
            if ($key !== null && $key !== $this->resourceKeys[$slot]) {
                $this->resourceKeys[$slot] = $key;
                $this->resources[$slot]->refresh($key);
            }

            return $this->resources[$slot];
        }

        $scope = $this->executionScope ?? throw new RuntimeException('Resource requires an execution scope.');
        $dirty = $this->dirty;
        $resource = new Resource($fetcher, $scope, static fn() => $dirty->request());
        $this->resourceKeys[$slot] = $key;

        if ($key !== null) {
            $resource->refresh($key);
        }

        $this->resources[$slot] = $resource;

        return $resource;
    }

    public function onDispose(Closure $callback): void
    {
        if ($this->disposed) {
            $callback();

            return;
        }

        $this->disposeStack[] = $callback;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        $syncKeys = array_keys($this->syncs);

        for ($i = count($syncKeys) - 1; $i >= 0; $i--) {
            try {
                $this->syncs[$syncKeys[$i]]->dispose();
            } catch (Throwable) {
            }
        }

        $watchKeys = array_keys($this->watches);

        for ($i = count($watchKeys) - 1; $i >= 0; $i--) {
            try {
                $this->watches[$watchKeys[$i]]->dispose();
            } catch (Throwable) {
            }
        }

        $resourceKeys = array_keys($this->resources);

        for ($i = count($resourceKeys) - 1; $i >= 0; $i--) {
            try {
                $this->resources[$resourceKeys[$i]]->dispose();
            } catch (Throwable) {
            }
        }

        $computedKeys = array_keys($this->computeds);

        for ($i = count($computedKeys) - 1; $i >= 0; $i--) {
            try {
                $this->computeds[$computedKeys[$i]]->dispose();
            } catch (Throwable) {
            }
        }

        for ($i = count($this->disposeStack) - 1; $i >= 0; $i--) {
            try {
                ($this->disposeStack[$i])();
            } catch (Throwable) {
            }
        }

        for ($i = count($this->subscriptions) - 1; $i >= 0; $i--) {
            $this->subscriptions[$i]->dispose();
        }

        for ($i = count($this->storeSubscriptions) - 1; $i >= 0; $i--) {
            $this->storeSubscriptions[$i]->dispose();
        }

        $signalKeys = array_keys($this->signals);

        for ($i = count($signalKeys) - 1; $i >= 0; $i--) {
            $this->signals[$signalKeys[$i]]->dispose();
        }

        $this->syncs = [];
        $this->watches = [];
        $this->resources = [];
        $this->resourceKeys = [];
        $this->computeds = [];
        $this->disposeStack = [];
        $this->subscriptions = [];
        $this->storeSubscriptions = [];
        $this->signals = [];
        $this->handles = [];
    }

    private function assertNotDisposed(): void
    {
        if ($this->disposed) {
            throw new RuntimeException('Cannot use a disposed stateful context.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\DevTools\DevToolsConfig;
use Phalanx\Theatron\DevTools\DockPosition;
use Phalanx\Theatron\Reactor\BackgroundReactor;
use Phalanx\Theatron\Reactor\StreamReactor;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\StoreDefinition;

final class TheatronBuilder
{
    private ?StatefulComponent $root = null;
    private ?DevToolsConfig $devTools = null;

    /** @var list<StoreDefinition> */
    private array $stores = [];

    /** @var list<ServiceBundle> */
    private array $providers = [];

    /** @var list<StreamReactor> */
    private array $streamReactors = [];

    /** @var list<BackgroundReactor> */
    private array $backgroundReactors = [];

    /** @var list<Slice> */
    private array $initialSlices = [];

    public function __construct(
        private AppContext $context,
    ) {
    }

    public function root(StatefulComponent $component): self
    {
        $this->root = $component;

        return $this;
    }

    public function store(StoreDefinition ...$stores): self
    {
        $this->stores = array_values([...$this->stores, ...$stores]);

        return $this;
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->providers = array_values([...$this->providers, ...$providers]);

        return $this;
    }

    public function reactors(StreamReactor ...$reactors): self
    {
        $this->streamReactors = array_values([...$this->streamReactors, ...$reactors]);

        return $this;
    }

    public function background(BackgroundReactor ...$reactors): self
    {
        $this->backgroundReactors = array_values([...$this->backgroundReactors, ...$reactors]);

        return $this;
    }

    public function initialState(Slice ...$slices): self
    {
        $this->initialSlices = array_values([...$this->initialSlices, ...$slices]);

        return $this;
    }

    public function devtools(
        DockPosition $position = DockPosition::Bottom,
        int $height = 8,
    ): self {
        $this->devTools = new DevToolsConfig($position, $height);

        return $this;
    }

    public function build(): TheatronApp
    {
        if ($this->root === null) {
            throw new \RuntimeException('Theatron root component is required.');
        }

        return new TheatronApp(
            root: $this->root,
            context: $this->context,
            stores: $this->stores,
            providers: $this->providers,
            streamReactors: $this->streamReactors,
            backgroundReactors: $this->backgroundReactors,
            initialSlices: $this->initialSlices,
            devTools: $this->devTools,
        );
    }
}

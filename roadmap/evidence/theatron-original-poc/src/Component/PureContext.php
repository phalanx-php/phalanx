<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Closure;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Tdom\Ui;
use RuntimeException;

final class PureContext implements Disposable
{
    public TaskScope $scope {
        get => $this->taskScope ?? throw new RuntimeException('No TaskScope available in this PureContext.');
    }

    private(set) Ui $ui;

    private bool $disposed = false;

    /** @var list<Closure(): void> */
    private array $disposeStack = [];

    public function __construct(private readonly ?TaskScope $taskScope = null)
    {
        $this->ui = new Ui();
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

        while ($this->disposeStack !== []) {
            (array_pop($this->disposeStack))();
        }
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Pool\BorrowedValue;
use Phalanx\Supervisor\TaskRun;

final class BorrowedScopeFrame implements BorrowedValue
{
    /** @var array<class-string, object> */
    public array $scopedInstances = [];

    /** @var list<class-string> */
    public array $scopedCreationOrder = [];

    /** @var list<Closure(): void> */
    public array $disposeStack = [];

    /** @var array<class-string, object> */
    public array $inheritedScopedInstances = [];

    /** @var list<int> */
    public array $deferredCids = [];

    /**
     * @var array<int, array{int, TaskRun}>
     */
    public array $goSpawns = [];

    public int $goSpawnSeq = 0;

    public bool $disposed = false;

    /**
     * @param array<class-string, object> $inheritedScopedInstances
     * @return Closure(self): void
     */
    public static function poolInitializer(array $inheritedScopedInstances): Closure
    {
        return static function (self $frame) use ($inheritedScopedInstances): void {
            $frame->scopedInstances = [];
            $frame->scopedCreationOrder = [];
            $frame->disposeStack = [];
            $frame->inheritedScopedInstances = $inheritedScopedInstances;
            $frame->deferredCids = [];
            $frame->goSpawns = [];
            $frame->goSpawnSeq = 0;
            $frame->disposed = false;
        };
    }
}

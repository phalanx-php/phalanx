<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Input;

use Phalanx\Scope\Cancellable;
use Phalanx\Theatron\Demos\Repl\Screen\ScreenStack;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Store\StoreWriter;
use Phalanx\Theatron\Stream\TheatronStream;

final class HotkeyContext
{
    public function __construct(
        private(set) Cancellable $scope,
        private(set) StoreWriter $writer,
        private(set) Lens $lens,
        private(set) DirtyBatch $dirty,
        private(set) ScreenStack $stack,
        private(set) Stage $stage,
        private(set) TheatronStream $stream,
    ) {
    }
}

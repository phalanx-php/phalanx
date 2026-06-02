<?php

declare(strict_types=1);

namespace Phalanx\Testing\Generated;

use Phalanx\Archon\Testing\ConsoleLens;
use Phalanx\Stoa\Testing\HttpLens;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;

/**
 * Generated typed-property accessors for TestApp.
 *
 * Emitted by phalanx-aegis via composer gen:test-accessors.
 * Edits are overwritten on the next dump.
 */
trait TestAppAccessors
{
    public ConsoleLens $console {
        get => $this->lens(ConsoleLens::class);
    }

    public HttpLens $http {
        get => $this->lens(HttpLens::class);
    }

    public LedgerLens $ledger {
        get => $this->lens(LedgerLens::class);
    }

    public RuntimeLens $runtime {
        get => $this->lens(RuntimeLens::class);
    }

    public ScopeLens $scope {
        get => $this->lens(ScopeLens::class);
    }
}

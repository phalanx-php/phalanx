<?php

declare(strict_types=1);

namespace Phalanx\Testing\Generated;

use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;

/**
 * Generated typed-property accessors for TestApp.
 *
 * Emitted by phalanx-aegis-codegen on composer post-autoload-dump. Edits
 * to this file are overwritten on the next dump.
 *
 * The kernel slice ships a hand-written body covering only the Aegis-native
 * lenses; downstream packages (Stoa's HttpLens, Archon's ConsoleLens, ...)
 * extend the generated trait once the codegen plugin lands.
 *
 * Property hooks are sorted alphabetically by accessor name for git
 * stability across regeneration runs.
 */
trait TestAppAccessors
{
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

<?php

declare(strict_types=1);

namespace Phalanx\Testing\Generated;

use Phalanx\Console\Testing\Lens as TestingLens;
use Phalanx\Http\Testing\Lens as TestingLens2;
use Phalanx\Testing\Lenses\ConfigLens;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;

/**
 * @generated
 *
 * Generator:   AccessorTraitWriter
 * Package:     phalanx-runtime
 * Regenerate:  composer dump-autoload
 *
 * This file is auto-generated. Do not edit — changes are
 * overwritten on the next generation pass.
 */
trait TestAppAccessors
{
    public ConfigLens $config {
        get => $this->lens(ConfigLens::class);
    }

    public TestingLens $console {
        get => $this->lens(TestingLens::class);
    }

    public TestingLens2 $http {
        get => $this->lens(TestingLens2::class);
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

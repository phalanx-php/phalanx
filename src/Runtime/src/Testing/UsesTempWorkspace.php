<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use PHPUnit\Framework\Attributes\After;

trait UsesTempWorkspace
{
    private ?TempWorkspace $tempWorkspace = null;

    protected function tempWorkspace(string $prefix = 'phalanx-test-'): TempWorkspace
    {
        return $this->tempWorkspace ??= TempWorkspace::create($prefix);
    }

    #[After]
    protected function cleanupTempWorkspace(): void
    {
        $this->disposeTempWorkspace();
    }

    protected function disposeTempWorkspace(): void
    {
        $workspace = $this->tempWorkspace;
        $this->tempWorkspace = null;
        $workspace?->cleanup();
    }
}

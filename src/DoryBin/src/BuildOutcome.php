<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

use Phalanx\DoryBin\Pipeline\StageResult;

final class BuildOutcome
{
    /** @param list<StageResult> $stages */
    public function __construct(
        private(set) bool $success,

        private(set) array $stages,

        private(set) ?string $binaryPath,

        private(set) ?BuildManifest $manifest,

        private(set) float $totalMs,
    ) {
    }

    public string $failedStage {
        get {
            $failed = array_find($this->stages, static fn(StageResult $r): bool => !$r->success && !$r->skipped);
            return $failed !== null ? $failed->stageName : '';
        }
    }

    public bool $complete {
        get => $this->success;
    }

    public static function dryRun(): self
    {
        return new self(
            success: true,
            stages: [],
            binaryPath: null,
            manifest: null,
            totalMs: 0.0,
        );
    }
}

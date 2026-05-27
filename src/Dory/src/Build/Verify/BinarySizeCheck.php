<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Verify;

use Phalanx\Dory\Build\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class BinarySizeCheck implements VerifyCheck
{
    /** @var array<string, int> Threshold in bytes per profile */
    private const array THRESHOLDS = [
        'mini'   => 25 * 1_048_576,
        'ops'    => 35 * 1_048_576,
        'brain'  => 35 * 1_048_576,
        'full'   => 50 * 1_048_576,
        'custom' => 50 * 1_048_576,
    ];

    public string $name = 'binary-size';

    public string $description = 'Verify the built binary is within the expected size threshold for the profile';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $size = filesize($binaryPath);

        if ($size === false) {
            return new VerifyResult($this->name, false, 'Could not stat binary file');
        }

        $profileKey = $profile->profile->value;
        $threshold = self::THRESHOLDS[$profileKey] ?? self::THRESHOLDS['full'];
        $sizeMb = $size / 1_048_576;
        $thresholdMb = $threshold / 1_048_576;

        if ($size > $threshold) {
            return new VerifyResult(
                $this->name,
                false,
                sprintf('Binary size %.1f MB exceeds %.0f MB threshold for %s profile', $sizeMb, $thresholdMb, $profile->profile->value),
            );
        }

        return new VerifyResult(
            $this->name,
            true,
            sprintf('Binary is %.1f MB (threshold: %.0f MB)', $sizeMb, $thresholdMb),
        );
    }
}

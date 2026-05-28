<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class ExtensionCheck implements VerifyCheck
{
    private(set) string $name = 'extensions';

    private(set) string $description = 'Verify all required extensions are loaded in the built binary';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $output = BinaryRunner::capture($scope, $binaryPath, 'echo json_encode(get_loaded_extensions());', 'verify.extensions.completed');

        if ($output === null) {
            return new VerifyResult($this->name, false, 'Failed to query loaded extensions from binary');
        }

        /** @var list<string>|null $loaded */
        $loaded = json_decode($output, true);

        if (!is_array($loaded)) {
            return new VerifyResult($this->name, false, 'Could not parse extension list from binary output');
        }

        $loadedLower = array_map('strtolower', $loaded);
        $missing = [];

        foreach ($profile->requiredExtensions as $required) {
            if (!in_array(strtolower($required), $loadedLower, strict: true)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            return new VerifyResult(
                $this->name,
                false,
                'Missing required extensions: ' . implode(', ', $missing),
            );
        }

        return new VerifyResult(
            $this->name,
            true,
            sprintf('%d required extension(s) present', count($profile->requiredExtensions)),
        );
    }
}

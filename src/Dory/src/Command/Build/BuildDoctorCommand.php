<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command\Build;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Dory\Build\BuildConfig;
use Phalanx\Dory\Build\BuildProfile;
use Phalanx\Dory\Build\BuildProfileRegistry;
use Phalanx\Dory\Build\Verify\BinarySizeCheck;
use Phalanx\Dory\Build\Verify\ExtensionCheck;
use Phalanx\Dory\Build\Verify\FiberContextCheck;
use Phalanx\Dory\Build\Verify\SmokeTestCheck;
use Phalanx\Dory\Build\Verify\SymbolConflictCheck;
use Phalanx\Task\Scopeable;

final class BuildDoctorCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $config = $ctx->service(BuildConfig::class);
        $binaryPath = (string) $ctx->args->get('binary', './dory');

        if (!is_file($binaryPath)) {
            $output->persist("Binary not found: {$binaryPath}");
            return 1;
        }

        $output->persist("Diagnosing: {$binaryPath}");
        $output->persist('  Size: ' . sprintf('%.1f MB', filesize($binaryPath) / 1_048_576));
        $output->persist('');

        $profileName = $config->defaultProfile;
        $registry = new BuildProfileRegistry(BuildProfileRegistry::defaultProfileDir());
        $profile = BuildProfile::tryFrom($profileName);
        $definition = $profile !== null ? $registry->get($profile) : $registry->getByName('full');

        $checks = [
            new ExtensionCheck(),
            new FiberContextCheck(),
            new SmokeTestCheck(),
            new SymbolConflictCheck(),
            new BinarySizeCheck(),
        ];

        $allPassed = true;

        foreach ($checks as $check) {
            $result = $check->check($ctx, $binaryPath, $definition);
            $marker = $result->passed ? '[pass]' : '[fail]';
            $output->persist("  {$marker} {$result->checkName}: {$result->message}");

            if (!$result->passed) {
                $allPassed = false;
            }
        }

        return $allPassed ? 0 : 1;
    }
}

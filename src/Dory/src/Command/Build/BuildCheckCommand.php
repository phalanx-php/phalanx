<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command\Build;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Dory\Build\BuildConfig;
use Phalanx\Dory\Build\BuildProfile;
use Phalanx\Dory\Build\BuildProfileRegistry;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Dory\Build\Stage\PreflightCheck;
use Phalanx\Task\Scopeable;

final class BuildCheckCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $config = $ctx->service(BuildConfig::class);

        $profileName = (string) $ctx->options->get('profile', $config->defaultProfile);

        $profile = BuildProfile::tryFrom($profileName);

        if ($profile === null) {
            $output->persist("Unknown profile: {$profileName}");
            return 1;
        }

        $registry = new BuildProfileRegistry(BuildProfileRegistry::defaultProfileDir());
        $definition = $registry->get($profile);
        $context = SpcBuildContext::forProfile($definition, $config);

        $check = new PreflightCheck();
        $result = $check($ctx, $context);

        foreach (explode('; ', $result->summary) as $line) {
            $output->persist("  {$line}");
        }

        return $result->success ? 0 : 1;
    }
}

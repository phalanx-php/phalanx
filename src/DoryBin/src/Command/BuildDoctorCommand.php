<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\DoryBin\BuildProfile;
use Phalanx\DoryBin\DoryBin;
use Phalanx\DoryBin\VerifyOptions;
use Phalanx\Task\Scopeable;

final class BuildDoctorCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $binaryPath = (string) $ctx->args->get('binary', './dory');

        if (!is_file($binaryPath)) {
            $output->persist("Binary not found: {$binaryPath}");
            return 1;
        }

        $output->persist("Diagnosing: {$binaryPath}");
        $output->persist('  Size: ' . sprintf('%.1f MB', filesize($binaryPath) / 1_048_576));
        $output->persist('');

        $options = new VerifyOptions(
            binaryPath: $binaryPath,
            profile: BuildProfile::Full,
        );

        $outcome = DoryBin::verify($ctx, $options);

        foreach ($outcome->results as $result) {
            $marker = $result->passed ? '[pass]' : '[fail]';
            $output->persist("  {$marker} {$result->checkName}: {$result->message}");
        }

        return $outcome->passed ? 0 : 1;
    }
}

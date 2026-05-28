<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Style\Theme;
use Phalanx\Archon\Widget\Table;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbConfig;
use ThreePath\Task\ScanForStbs;

final class ScanCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        /** @var CommandContext $scope */
        /** @var StbConfig $config */
        $config = $scope->service(StbConfig::class);
        $cidr = $scope->args->get('cidr') ?? $config->defaultSubnet;

        echo "Scanning {$cidr}...\n";

        $theme    = Theme::default();
        $output   = new StreamOutput();
        $table    = new Table($theme);
        $observer = new StbScanObserver($output, $table, $theme);

        $found = $scope->execute(
            (new ScanForStbs($cidr))->withObserver($observer),
        );

        return $found !== [] ? 0 : 1;
    }
}

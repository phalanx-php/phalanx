<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\SupervisedConcurrency;

use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\BuildStage;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\PackageStage;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\RetryStage;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\ShipStage;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\TestStage;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\TimeoutStage;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\ConcurrentTaskList;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Task\Executable;

/**
 * Runs four deploy stages concurrently with a live spinner UI. TestStage is
 * the canonical example of a transient failure: it throws on its first two
 * attempts, then passes — RetryStage gives it a 3-attempt budget so the
 * overall deploy still succeeds. ShipStage is wrapped in a 2.0s timeout
 * so a stalled upload is caught at the scope boundary.
 */
final class DeployCommand implements Executable
{
    public function __invoke(CommandScope $scope): int
    {
        $env = (string) $scope->args->get('env', 'staging');

        $theme  = $scope->service(Theme::class);
        $output = $scope->service(StreamOutput::class);

        $output->persist("deploy → {$env}");

        // Reset TestStage's static counter so the demo is deterministic across runs.
        TestStage::$attempts = 0;

        (new ConcurrentTaskList($scope, $output, $theme))
            ->add('build',   'Build',   new BuildStage())
            ->add('test',    'Test',    new RetryStage(new TestStage(), RetryPolicy::exponential(3, 30.0, 100.0)))
            ->add('package', 'Package', new PackageStage())
            ->add('ship',    'Ship',    new TimeoutStage(new ShipStage(), 2.0))
            ->run();

        $output->persist("deploy: 4 stages settled (test attempts: " . TestStage::$attempts . ")");

        return 0;
    }
}

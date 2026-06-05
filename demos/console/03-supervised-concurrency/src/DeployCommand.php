<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\SupervisedConcurrency;

use Phalanx\Demos\Console\SupervisedConcurrency\Stages\BuildStage;
use Phalanx\Demos\Console\SupervisedConcurrency\Stages\PackageStage;
use Phalanx\Demos\Console\SupervisedConcurrency\Stages\RetryStage;
use Phalanx\Demos\Console\SupervisedConcurrency\Stages\ShipStage;
use Phalanx\Demos\Console\SupervisedConcurrency\Stages\TestStage;
use Phalanx\Demos\Console\SupervisedConcurrency\Stages\TimeoutStage;
use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Console\Output\StreamOutput;
use Phalanx\Console\Console\Style\Theme;
use Phalanx\Console\Console\Widget\ConcurrentTaskList;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Backoff;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Task\Executable;

/**
 * Runs four deploy stages concurrently with a live spinner UI. TestStage is
 * the canonical example of a transient failure: it throws on its first two
 * attempts, then passes — RetryStage gives it a 3-attempt budget so the
 * overall deploy still succeeds. ShipStage is wrapped in a 2.0s timeout
 * so a stalled upload is caught at the scope boundary.
 */
final class DeployCommand implements Executable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Run 4 deploy stages concurrently with a live UI.',
            arguments: [Arg::optional('env', 'Target environment.', 'staging')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $env = (string) $ctx->args->get('env', 'staging');

        $theme  = $ctx->service(Theme::class);
        $output = $ctx->service(StreamOutput::class);

        $output->persist("deploy → {$env}");

        // Reset TestStage's static counter so the demo is deterministic across runs.
        TestStage::$attempts = 0;

        (new ConcurrentTaskList($ctx, $output, $theme))
            ->add('build',   'Build',   new BuildStage())
            ->add('test',    'Test',    new RetryStage(new TestStage(), RecoveryPlan::defaultRetry(attempts: 3, backoff: Backoff::exponential(Mark::ms(30), Mark::ms(100)))))
            ->add('package', 'Package', new PackageStage())
            ->add('ship',    'Ship',    new TimeoutStage(new ShipStage(), Mark::s(2)))
            ->run();

        $output->persist("deploy: 4 stages settled (test attempts: " . TestStage::$attempts . ")");

        return 0;
    }
}

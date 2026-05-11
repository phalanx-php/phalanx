<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

/**
 * Phalanx Ultra-Deep Ledger Demo
 * 
 * Demonstrates:
 * 1. Sibling Arrow Logic
 * 2. High-Depth Panning (Level 12+)
 */
return DemoReport::demo(
    'Archon Error Diagnostics: Ultra-Deep Hierarchy',
    static function (DemoReport $report, AppContext $context): void {
        $report->note('This demo triggers a failure at level 15 to showcase hierarchy panning and the ⇗ connector.');

        $app = Archon::starting($context->values)
            ->command('demo:ultra-deep', static function (CommandScope $scope) {
                
                $buildDeepTree = static function (ExecutionScope $scope, int $depth, int $maxDepth, Closure $self): void {
                    if ($depth >= $maxDepth) {
                        throw new \RuntimeException("Deep-sea failure at level {$depth}!");
                    }

                    // Sibling demonstration at level 3
                    if ($depth === 3) {
                        $scope->go(static fn(ExecutionScope $s) => $s->delay(10.0), 'background.worker_a');
                        $scope->go(static fn(ExecutionScope $s) => $s->delay(10.0), 'background.worker_b');
                    }

                    // Use execute for strict vertical nesting
                    $scope->execute(Task::named("layer_{$depth}", static function (ExecutionScope $child) use ($depth, $maxDepth, $self) {
                        $self($child, $depth + 1, $maxDepth, $self);
                    }));
                };

                // Level 12 will trigger panning (12 - 10 = 2 levels shifted)
                $buildDeepTree($scope, 1, 15, $buildDeepTree);
            })
            ->build();

        $code = $app->run(['demo:ultra-deep']);
        $app->shutdown();

        $report->record('Command exited with non-zero (deep exception handled)', $code === 1);
    },
);

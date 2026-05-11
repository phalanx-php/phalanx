<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;

/**
 * Phalanx Deep Error Rendering Demo (Complex Hierarchy)
 * 
 * Demonstrates:
 * 1. 3+ levels of nesting
 * 2. Mixed Siblings at every level (↳ for first-born only)
 * 3. Cohesive layout and vertical alignment
 */
return DemoReport::demo(
    'Archon Error Diagnostics: Basic Hierarchy',
    static function (DemoReport $report, AppContext $context): void {
        $report->note('This demo triggers an expected RuntimeException to showcase rich error rendering.');
        
        $app = Archon::starting($context->values)
            ->command('demo:deep-error', static function (CommandScope $scope) {
                
                // Spawn a complex background tree
                $scope->go(static function (ExecutionScope $scope) {
                    
                    // Sibling at Level 2
                    $scope->go(static function (ExecutionScope $scope) {
                        
                        // Sibling at Level 3
                        $scope->go(static function (ExecutionScope $scope) {
                             $scope->delay(10.0);
                        }, 'ledger.level_3_active');

                        $scope->go(static function (ExecutionScope $scope) {
                             $scope->delay(10.0);
                        }, 'ledger.level_3_sibling');

                        $scope->delay(10.0);
                    }, 'ledger.level_2_active');

                    $scope->go(static function (ExecutionScope $scope) {
                         $scope->delay(10.0);
                    }, 'ledger.level_2_sibling');

                    $scope->delay(10.0);
                }, 'ledger.level_1_active');

                // Sibling at Level 1
                $scope->go(static function (ExecutionScope $scope) {
                    $scope->delay(10.0);
                }, 'ledger.level_1_sibling');

                // Small delay to ensure all fibers are registered
                $scope->delay(0.1);

                throw new \RuntimeException(
                    "Critical failure in POC logic: Database connection lost!\n" .
                    "at Phalanx\\Database\\Driver\\PdoDriver->execute()"
                );
            })
            ->build();

        $code = $app->run(['demo:deep-error']);
        $app->shutdown();

        // In this specific demo, exit code 1 is the expected "success" since we want to trigger the renderer
        $report->record('Command exited with non-zero (exception handled)', $code === 1);
    },
);

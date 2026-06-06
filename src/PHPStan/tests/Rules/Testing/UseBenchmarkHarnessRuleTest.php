<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\UseBenchmarkHarnessRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UseBenchmarkHarnessRule>
 */
final class UseBenchmarkHarnessRuleTest extends RuleTestCase
{
    public function testFlagsBootCallsInsideBenchmarkDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/benchmarks/BenchmarkHarnessViolation.php'],
            [
                [
                    'Benchmarks should boot through BenchmarkHarness instead of Phalanx\Application::starting(). '
                    . 'Bypassing the harness skips pool-stats collection, ZMM tracking, and scope-clean assertions.',
                    19,
                ],
                [
                    'Benchmarks should boot through BenchmarkHarness instead of Phalanx\Http\Server::starting(). '
                    . 'Bypassing the harness skips pool-stats collection, ZMM tracking, and scope-clean assertions.',
                    24,
                ],
                [
                    'Benchmarks should boot through BenchmarkHarness instead of '
                    . 'Phalanx\Console\Console::starting(). '
                    . 'Bypassing the harness skips pool-stats collection, ZMM tracking, and scope-clean assertions.',
                    29,
                ],
            ],
        );
    }

    public function testIgnoresBenchmarkInfrastructure(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/benchmarks/_kit/BenchmarkInfrastructure.php'],
            [],
        );
    }

    public function testIgnoresBootCallsOutsideBenchmarkDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UseBenchmarkHarnessOutsideBenchmarkDir.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new UseBenchmarkHarnessRule();
    }
}

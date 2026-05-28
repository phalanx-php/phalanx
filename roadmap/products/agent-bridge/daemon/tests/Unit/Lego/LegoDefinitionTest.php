<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Lego;

use AgentBridge\Lego\LegoDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LegoDefinitionTest extends TestCase
{
    private static function make(
        string $name = 'test-lego',
        string $domain = 'example.com',
        array $steps = [],
        int $executions = 0,
        int $failures = 0,
        int $overrides = 0,
    ): LegoDefinition {
        return new LegoDefinition(
            name:        $name,
            domain:      $domain,
            description: 'A test lego',
            steps:       $steps,
            executions:  $executions,
            failures:    $failures,
            overrides:   $overrides,
        );
    }

    #[Test]
    public function confidence_is_neutral_before_first_run(): void
    {
        $lego = self::make(executions: 0);

        self::assertSame(0.5, $lego->confidence);
    }

    #[Test]
    public function confidence_reflects_success_rate(): void
    {
        // 10 executions, 2 failures = 80% success rate, no overrides
        $lego = self::make(executions: 10, failures: 2, overrides: 0);

        self::assertEqualsWithDelta(0.8, $lego->confidence, 0.0001);
    }

    #[Test]
    public function confidence_penalized_by_overrides(): void
    {
        // 10 executions, 0 failures = 1.0 base; 3 overrides = 0.3 penalty -> 0.7
        $lego = self::make(executions: 10, failures: 0, overrides: 3);

        self::assertEqualsWithDelta(0.7, $lego->confidence, 0.0001);
    }

    #[Test]
    public function override_penalty_is_capped_at_0_5(): void
    {
        // 10 overrides -> min(10 * 0.1, 0.5) = 0.5 penalty; 1.0 base -> 0.5
        $lego = self::make(executions: 10, failures: 0, overrides: 10);

        self::assertEqualsWithDelta(0.5, $lego->confidence, 0.0001);

        // 20 overrides -> same cap, still 0.5
        $lego20 = self::make(executions: 10, failures: 0, overrides: 20);
        self::assertEqualsWithDelta(0.5, $lego20->confidence, 0.0001);
    }

    #[Test]
    public function confidence_clamped_to_zero_at_lower_bound(): void
    {
        // 10 executions, 10 failures = 0% success, max override penalty = result cannot go below 0
        $lego = self::make(executions: 10, failures: 10, overrides: 10);

        self::assertGreaterThanOrEqual(0.0, $lego->confidence);
        self::assertSame(0.0, $lego->confidence);
    }

    #[Test]
    public function confidence_clamped_to_one_at_upper_bound(): void
    {
        // Perfect record: no failures, no overrides -> 1.0 (not exceeding 1)
        $lego = self::make(executions: 100, failures: 0, overrides: 0);

        self::assertSame(1.0, $lego->confidence);
    }

    #[Test]
    public function from_array_round_trips_through_to_array(): void
    {
        $original = new LegoDefinition(
            name:         'click-submit',
            domain:       'example.com',
            description:  'Clicks the submit button',
            steps:        [['op' => 'click', 'selector' => '#submit']],
            executions:   5,
            failures:     1,
            overrides:    0,
            lastVerified: '2026-04-05T00:00:00+00:00',
        );

        $roundTripped = LegoDefinition::fromArray($original->toArray());

        self::assertSame($original->name,         $roundTripped->name);
        self::assertSame($original->domain,       $roundTripped->domain);
        self::assertSame($original->description,  $roundTripped->description);
        self::assertSame($original->steps,        $roundTripped->steps);
        self::assertSame($original->executions,   $roundTripped->executions);
        self::assertSame($original->failures,     $roundTripped->failures);
        self::assertSame($original->overrides,    $roundTripped->overrides);
        self::assertSame($original->lastVerified, $roundTripped->lastVerified);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_optional_fields(): void
    {
        $lego = LegoDefinition::fromArray([
            'name'   => 'minimal',
            'domain' => 'example.com',
            'steps'  => [],
        ]);

        self::assertSame('',  $lego->description);
        self::assertSame(0,   $lego->executions);
        self::assertSame(0,   $lego->failures);
        self::assertSame(0,   $lego->overrides);
        self::assertNull($lego->lastVerified);
    }

    #[Test]
    public function with_execution_succeeded_increments_executions_only(): void
    {
        $original = self::make(executions: 3, failures: 1);
        $updated  = $original->withExecution(succeeded: true);

        self::assertSame(4, $updated->executions);
        self::assertSame(1, $updated->failures);

        // Original is unchanged (copy-on-write)
        self::assertSame(3, $original->executions);
    }

    #[Test]
    public function with_execution_failed_increments_both_counters(): void
    {
        $original = self::make(executions: 3, failures: 1);
        $updated  = $original->withExecution(succeeded: false);

        self::assertSame(4, $updated->executions);
        self::assertSame(2, $updated->failures);
    }

    #[Test]
    public function with_repaired_steps_resets_failures_and_clears_last_verified(): void
    {
        $original = self::make(executions: 5, failures: 3);
        $original = new LegoDefinition(
            name:         $original->name,
            domain:       $original->domain,
            description:  $original->description,
            steps:        $original->steps,
            executions:   5,
            failures:     3,
            overrides:    1,
            lastVerified: '2026-04-01T00:00:00+00:00',
        );

        $newSteps = [['op' => 'click', 'selector' => '#new-btn']];
        $repaired = $original->withRepairedSteps($newSteps);

        self::assertSame($newSteps, $repaired->steps);
        self::assertSame(0,         $repaired->failures);
        self::assertNull($repaired->lastVerified);

        // Unchanged counters
        self::assertSame(5, $repaired->executions);
        self::assertSame(1, $repaired->overrides);
    }
}

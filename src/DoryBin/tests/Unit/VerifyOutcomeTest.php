<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use Phalanx\DoryBin\Verify\VerifyResult;
use Phalanx\DoryBin\VerifyOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifyOutcome::class)]
final class VerifyOutcomeTest extends TestCase
{
    #[Test]
    public function failures_returns_only_failed_results(): void
    {
        $outcome = new VerifyOutcome(
            passed: false,
            results: [
                new VerifyResult('binary-size', true, 'size OK'),
                new VerifyResult('symbol-conflict', false, 'duplicate symbol detected'),
                new VerifyResult('extension-check', true, 'extensions loaded'),
                new VerifyResult('fiber-context', false, 'fiber context missing'),
            ],
            binaryPath: '/tmp/apollo-build/bin/dory',
            totalMs: 280.0,
        );

        $failures = $outcome->failures();

        self::assertCount(2, $failures);
        $names = array_map(static fn(VerifyResult $r) => $r->checkName, $failures);
        self::assertContains('symbol-conflict', $names);
        self::assertContains('fiber-context', $names);
    }

    #[Test]
    public function failures_returns_empty_when_all_pass(): void
    {
        $outcome = new VerifyOutcome(
            passed: true,
            results: [
                new VerifyResult('binary-size', true, 'size OK'),
                new VerifyResult('symbol-conflict', true, 'no conflicts'),
            ],
            binaryPath: '/tmp/dory',
            totalMs: 50.0,
        );

        self::assertSame([], $outcome->failures());
    }

    #[Test]
    public function failures_returns_empty_when_no_results(): void
    {
        $outcome = new VerifyOutcome(
            passed: true,
            results: [],
            binaryPath: '/tmp/dory',
            totalMs: 0.0,
        );

        self::assertSame([], $outcome->failures());
    }

    #[Test]
    public function failures_returns_re_indexed_list(): void
    {
        // array_values ensures it's a list, not a sparse array
        $outcome = new VerifyOutcome(
            passed: false,
            results: [
                new VerifyResult('extension-check', true, 'OK'),
                new VerifyResult('smoke-test', false, 'script failed'),
            ],
            binaryPath: '/tmp/dory',
            totalMs: 100.0,
        );

        $failures = $outcome->failures();

        self::assertArrayHasKey(0, $failures);
        self::assertSame('smoke-test', $failures[0]->checkName);
    }

    #[Test]
    public function properties_are_accessible(): void
    {
        $result = new VerifyResult('symbol-conflict', true, 'clean');
        $outcome = new VerifyOutcome(
            passed: true,
            results: [$result],
            binaryPath: '/opt/phalanx/bin/dory',
            totalMs: 125.5,
        );

        self::assertTrue($outcome->passed);
        self::assertSame('/opt/phalanx/bin/dory', $outcome->binaryPath);
        self::assertSame(125.5, $outcome->totalMs);
        self::assertCount(1, $outcome->results);
    }
}

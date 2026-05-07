<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Process\ManagedProcessOnlyRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ManagedProcessOnlyRule>
 */
final class ManagedProcessOnlyRuleTest extends RuleTestCase
{
    public function testReportsRawProcessManagement(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/managed-process-only.php'],
            [
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_open() in package code.', 14],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_get_status() in package code.', 15],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_terminate() in package code.', 16],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_close() in package code.', 17],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of Symfony Process construction in package code.', 19],
            ],
        );
    }

    public function testAllowsSanctionedProcessInternals(): void
    {
        $fixture = __DIR__ . '/Fixtures/managed-process-only-internal.php';

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new ManagedProcessOnlyRule(
            new PathPolicy(),
            [__DIR__ . '/Fixtures/managed-process-only-internal.php'],
        );
    }
}

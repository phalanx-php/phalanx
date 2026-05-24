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
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_open() in package code.', 17],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_get_status() in package code.', 18],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_terminate() in package code.', 19],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of proc_close() in package code.', 20],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of Symfony\Component\Process\Process construction in package code.', 22],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of Symfony\Component\Process\Process::fromShellCommandline() in package code.', 23],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of OpenSwoole\Process construction in package code.', 24],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of OpenSwoole\Process\Pool construction in package code.', 26],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of Swoole\Process construction in package code.', 27],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of Swoole\Process\Pool construction in package code.', 29],
                ['Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of OpenSwoole\Core\Process\Manager construction in package code.', 30],
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

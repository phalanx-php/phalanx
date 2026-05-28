<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Verify;

use Phalanx\Cancellation\Cancelled;
use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class SymbolConflictCheck implements VerifyCheck
{
    private(set) string $name = 'symbol-conflict';

    private(set) string $description = 'Check for duplicate curl symbol definitions that indicate a linking conflict';

    public function check(TaskScope&TaskExecutor $scope, string $binaryPath, BuildProfileDefinition $profile): VerifyResult
    {
        $nm = self::findNm();

        if ($nm === null) {
            return new VerifyResult($this->name, true, 'nm not available; skipped');
        }

        $count = self::countSymbol($scope, $nm, $binaryPath, 'curl_share_ce');

        if ($count === null) {
            return new VerifyResult($this->name, true, 'nm produced no output or failed; skipped');
        }

        if ($count >= 2) {
            return new VerifyResult(
                $this->name,
                false,
                "Symbol 'curl_share_ce' appears {$count} times — likely a duplicate curl linking conflict",
            );
        }

        return new VerifyResult(
            $this->name,
            true,
            "No duplicate symbol conflicts detected (curl_share_ce count: {$count})",
        );
    }

    private static function findNm(): ?string
    {
        foreach (['/usr/bin/nm', '/usr/local/bin/nm'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function countSymbol(TaskScope&TaskExecutor $scope, string $nm, string $binaryPath, string $symbol): ?int
    {
        $handle = StreamingProcess::command([$nm, '-g', $binaryPath])->start($scope);
        $count = 0;
        $tail = '';

        try {
            while (true) {
                $chunk = $handle->getIncrementalOutput();
                if ($chunk !== '') {
                    $count += substr_count($tail . $chunk, $symbol) - substr_count($tail, $symbol);
                    $tail = strlen($chunk) >= strlen($symbol)
                        ? substr($chunk, -strlen($symbol) + 1)
                        : substr($tail . $chunk, -strlen($symbol) + 1);
                }

                $exitCode = $handle->wait(0.01);
                if ($exitCode !== null) {
                    $chunk = $handle->getIncrementalOutput();
                    if ($chunk !== '') {
                        $count += substr_count($tail . $chunk, $symbol) - substr_count($tail, $symbol);
                    }
                    $handle->close('verify.symbol-conflict.completed');
                    return $exitCode === 0 ? $count : null;
                }
            }
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable) {
            $handle->kill();
            return null;
        }
    }
}

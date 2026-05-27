<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Spc;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class SpcRunner
{
    private const int STDERR_MAX = 16384;

    private const int STDERR_TAIL = 8192;

    public function __construct(
        private(set) SpcBuildContext $context,
    ) {
    }

    public function download(TaskScope&TaskExecutor $scope): SpcResult
    {
        $extensions = implode(',', $this->context->profile->allExtensions());

        return $this->run($scope, [
            'download',
            '--for-extensions=' . $extensions,
            '--with-php=' . $this->context->profile->phpVersion,
        ]);
    }

    public function buildLibs(TaskScope&TaskExecutor $scope): SpcResult
    {
        return $this->run($scope, ['build', '--build-libs-only']);
    }

    public function buildPhp(TaskScope&TaskExecutor $scope): SpcResult
    {
        $extensions = implode(',', $this->context->profile->allExtensions());

        $args = [
            'build',
            'php-cli',
            '--with-extensions=' . $extensions,
        ];

        $iniPath = $this->context->profile->iniPath;
        $iniScanDir = $this->context->profile->iniScanDir;

        if ($iniPath !== '') {
            $args[] = '--with-config-file-path=' . $iniPath;
        }

        if ($iniScanDir !== '') {
            $args[] = '--with-config-file-scan-dir=' . $iniScanDir;
        }

        return $this->run($scope, $args);
    }

    /** @param list<string> $args */
    private function run(TaskScope&TaskExecutor $scope, array $args): SpcResult
    {
        $argv = [$this->context->spcBinaryPath, ...$args];

        $start = hrtime(true);
        $stderr = '';
        $exitCode = 0;

        $handle = (new StreamingProcess($argv, cwd: $this->context->buildRoot))
            ->withEnv($this->context->environment)
            ->start($scope);

        try {
            while (true) {
                $handle->getIncrementalOutput(); // discard -- only stderr is diagnostically useful
                $chunk = $handle->getIncrementalErrorOutput();
                if ($chunk !== '') {
                    $stderr = self::appendCapped($stderr, $chunk, self::STDERR_MAX, self::STDERR_TAIL);
                }

                $code = $handle->wait(0.05);

                if ($code !== null) {
                    $exitCode = $code;
                    break;
                }
            }

            $handle->getIncrementalOutput(); // discard
            $chunk = $handle->getIncrementalErrorOutput();
            if ($chunk !== '') {
                $stderr = self::appendCapped($stderr, $chunk, self::STDERR_MAX, self::STDERR_TAIL);
            }
            $handle->close('spc.completed');
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable $e) {
            $handle->kill();
            throw $e;
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new SpcResult($exitCode, '', $stderr, $durationMs);
    }

    private static function appendCapped(string $buffer, string $chunk, int $max, int $tail): string
    {
        $buffer .= $chunk;

        if (strlen($buffer) > $max) {
            $buffer = substr($buffer, -$tail);
        }

        return $buffer;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Spc;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class SpcRunner
{
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
                    $stderr .= $chunk;
                    if (strlen($stderr) > 16384) {
                        $stderr = substr($stderr, -8192);
                    }
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
                $stderr .= $chunk;
                if (strlen($stderr) > 16384) {
                    $stderr = substr($stderr, -8192);
                }
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
}

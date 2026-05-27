<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\Cancellation\Cancelled;
use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class PatchOpenSwoole implements BuildStage
{
    public string $name = 'patch-openswoole';

    public string $description = 'Patch OpenSwoole sources for static build';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);
        $extDir = $context->sourcePath . '/php-src/ext/openswoole';

        $patched = self::patchSources($extDir, $scope, $context);

        if (!$patched) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: (hrtime(true) - $start) / 1_000_000,
                summary: 'OpenSwoole config.m4 not found at ' . $extDir,
            );
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: 'OpenSwoole sources patched for static build',
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }

    private static function patchConfigM4(string $sourceDir): bool
    {
        $file = $sourceDir . '/config.m4';

        if (!is_file($file)) {
            return false;
        }

        $content = (string) file_get_contents($file);

        $content = str_replace('-std=c99', '-std=gnu99', $content);

        $content = (string) preg_replace(
            '/openswoole_source_file="\$openswoole_source_file\s*\\\\\n\s+thirdparty\/nghttp2\/[^"]+"/s',
            '',
            $content,
        );

        $content = str_replace('SW_USE_ASM_CONTEXT="yes"', 'SW_USE_ASM_CONTEXT="no"', $content);

        file_put_contents($file, $content);

        return true;
    }

    private static function patchSources(string $extDir, TaskScope&TaskExecutor $scope, SpcBuildContext $context): bool
    {
        if (!self::patchConfigM4($extDir)) {
            return false;
        }

        $httpHeader = $extDir . '/ext-src/php_openswoole_http.h';

        if (is_file($httpHeader)) {
            $content = (string) file_get_contents($httpHeader);
            $content = str_replace(
                '#include "thirdparty/nghttp2/nghttp2.h"',
                '#include <nghttp2/nghttp2.h>',
                $content,
            );
            file_put_contents($httpHeader, $content);
        }

        $curlInterface = $extDir . '/thirdparty/php/curl/interface.cc';

        if (is_file($curlInterface)) {
            $content = (string) file_get_contents($curlInterface);
            $content = str_replace(
                'zend_class_entry *curl_share_ce;',
                'extern zend_class_entry *curl_share_ce;',
                $content,
            );
            file_put_contents($curlInterface, $content);
        }

        if ($context->os === 'Darwin') {
            self::patchMacOsUtil($extDir, $scope, $context);
        }

        return true;
    }

    private static function patchMacOsUtil(string $extDir, TaskScope&TaskExecutor $scope, SpcBuildContext $context): void
    {
        $procOpen = $extDir . '/thirdparty/php/standard/proc_open.cc';

        if (!is_file($procOpen)) {
            return;
        }

        $sdkPath = self::detectSdkPath($scope, $context);

        if ($sdkPath === '') {
            return;
        }

        $utilPath = $sdkPath . '/usr/include/util.h';

        $content = (string) file_get_contents($procOpen);
        $content = str_replace('include <util.h>', "include \"{$utilPath}\"", $content);
        file_put_contents($procOpen, $content);
    }

    private static function detectSdkPath(TaskScope&TaskExecutor $scope, SpcBuildContext $context): string
    {
        $handle = StreamingProcess::command(['xcrun', '--show-sdk-path'])
            ->withCwd($context->buildRoot)
            ->start($scope);

        $output = '';

        try {
            $exitCode = $handle->wait();

            if ($exitCode !== 0) {
                $handle->close('xcrun.failed');
                return '';
            }

            $output = trim($handle->getIncrementalOutput());
            $handle->close('xcrun.completed');
        } catch (Cancelled $e) {
            $handle->kill();
            throw $e;
        } catch (\Throwable) {
            $handle->kill();
            return '';
        }

        return $output;
    }
}

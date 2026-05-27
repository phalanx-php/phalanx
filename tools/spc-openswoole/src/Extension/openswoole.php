<?php

declare(strict_types=1);

namespace PhalanxSpc\Extension;

use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\CustomPhpConfigureArg;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\Package\Validate;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\WrongUsageException;
use StaticPHP\Package\PackageBuilder;
use StaticPHP\Package\PackageInstaller;
use StaticPHP\Package\PhpExtensionPackage;
use StaticPHP\Runtime\SystemTarget;
use StaticPHP\Util\FileSystem;
use Package\Target\php;

#[Extension('openswoole')]
class openswoole extends PhpExtensionPackage
{
    #[Validate]
    public function validate(PackageInstaller $installer): void
    {
        if ($installer->getPhpExtensionPackage('swoole')) {
            throw new WrongUsageException('openswoole and swoole cannot coexist — they register the same internal classes');
        }
    }

    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-openswoole')]
    #[PatchDescription('Copy OpenSwoole source into php-src (workaround for ArtifactLoader init race)')]
    public function ensureSourceInPlace(): void
    {
        $target = SOURCE_PATH . '/php-src/ext/' . $this->getExtensionName();
        $stash = SOURCE_PATH . '/ext-openswoole-stash';

        if (!is_dir($stash)) {
            throw new WrongUsageException(
                "OpenSwoole source stash not found at {$stash}. "
                . 'Download openswoole/ext-openswoole 26.2.0 and extract to that path.'
            );
        }

        shell()->exec("rm -rf {$target}");
        mkdir($target, 0755, true);
        shell()->exec("cp -R {$stash}/* {$target}/");
    }

    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-openswoole')]
    #[PatchDescription('Patch config.m4 to avoid duplicate symbols in static build')]
    public function patchConfigM4ForStaticBuild(): void
    {
        $file = SOURCE_PATH . '/php-src/ext/openswoole/config.m4';

        if (!is_file($file)) {
            return;
        }

        $content = file_get_contents($file);

        // Fix -std=c99 → -std=gnu99 (breaks GNU asm in PHP core)
        $content = str_replace('-std=c99', '-std=gnu99', $content);

        // Remove bundled nghttp2 source block (use system/buildroot nghttp2 instead)
        $content = preg_replace(
            '/openswoole_source_file="\$openswoole_source_file\s*\\\\\n\s+thirdparty\/nghttp2\/[^"]+"/s',
            '',
            $content,
        );

        // Force SW_USE_ASM_CONTEXT=no to avoid boost ASM colliding with PHP 8.4 Zend fibers
        $content = str_replace('SW_USE_ASM_CONTEXT="yes"', 'SW_USE_ASM_CONTEXT="no"', $content);

        file_put_contents($file, $content);
    }

    #[BeforeStage('php', [php::class, 'makeForUnix'], 'ext-openswoole')]
    #[PatchDescription('Patch OpenSwoole sources for static PHP build compatibility')]
    public function patchSourcesForStaticBuild(): void
    {
        $extDir = SOURCE_PATH . '/php-src/ext/openswoole';

        // Redirect nghttp2 include to buildroot headers
        $httpHeader = "{$extDir}/ext-src/php_openswoole_http.h";

        if (is_file($httpHeader)) {
            FileSystem::replaceFileStr(
                $httpHeader,
                '#include "thirdparty/nghttp2/nghttp2.h"',
                '#include <nghttp2/nghttp2.h>',
            );
        }

        // Fix curl_share_ce duplicate: make it extern in OpenSwoole's copy
        $curlInterface = "{$extDir}/thirdparty/php/curl/interface.cc";

        if (is_file($curlInterface)) {
            $content = file_get_contents($curlInterface);
            $content = str_replace(
                'zend_class_entry *curl_share_ce;',
                'extern zend_class_entry *curl_share_ce;',
                $content,
            );
            file_put_contents($curlInterface, $content);
        }

        // Fix util.h conflict on macOS
        if (SystemTarget::getTargetOS() === 'Darwin') {
            $procOpen = "{$extDir}/thirdparty/php/standard/proc_open.cc";

            if (is_file($procOpen)) {
                $sdkPath = shell()->execWithResult('xcrun --show-sdk-path', false)[1][0] ?? '';

                if ($sdkPath !== '') {
                    $utilPath = $sdkPath . '/usr/include/util.h';
                    FileSystem::replaceFileStr($procOpen, 'include <util.h>', "include \"{$utilPath}\"");
                }
            }
        }
    }

    #[CustomPhpConfigureArg('Darwin')]
    #[CustomPhpConfigureArg('Linux')]
    public function getUnixConfigureArg(bool $shared, PackageBuilder $builder, PackageInstaller $installer): string
    {
        $arg = '--enable-openswoole' . ($shared ? '=shared' : '');
        $arg .= ' --with-pic';
        $arg .= ' --enable-openssl';
        $arg .= ' --enable-http2';
        $arg .= ' --enable-hook-curl';

        if ($installer->getLibraryPackage('libcares')) {
            $arg .= ' --enable-cares';
        }

        if ($installer->getPhpExtensionPackage('sockets')) {
            $arg .= ' --enable-sockets';
        }

        if ($installer->getPhpExtensionPackage('openswoole-hook-pgsql')) {
            $arg .= ' --with-postgres=' . BUILD_ROOT_PATH;
        }

        if ($installer->getPhpExtensionPackage('openswoole-hook-mysql')) {
            $arg .= ' --enable-mysqlnd';
        }

        if (SystemTarget::getTargetOS() === 'Darwin') {
            $arg .= ' ac_cv_lib_pthread_pthread_barrier_init=no';
        }

        return $arg;
    }
}

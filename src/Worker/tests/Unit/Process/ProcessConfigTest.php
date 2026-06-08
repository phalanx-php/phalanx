<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit\Process;

use Phalanx\Testing\FixtureFile;
use Phalanx\Worker\Process\ProcessConfig;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessConfigTest extends TestCase
{
    #[Test]
    public function workerCommandStartsWithPhpBinary(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        self::assertSame(PHP_BINARY, $cmd[0]);
    }

    #[Test]
    public function workerCommandIncludesScriptAndAutoload(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        self::assertContains('/tmp/worker', $cmd);
        self::assertContains('--autoload=/tmp/autoload.php', $cmd);
    }

    #[Test]
    public function workerCommandRedirectsErrorsToStderr(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        $found = false;
        $count = count($cmd);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($cmd[$i] === '-d' && $cmd[$i + 1] === 'display_errors=stderr') {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'workerCommand() must include -d display_errors=stderr to protect the JSON protocol channel');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function workerCommandSkipsSwooleWhenIniConfigured(): void
    {
        $config = new ProcessConfig(workerScript: '/tmp/worker', autoloadPath: '/tmp/autoload.php');

        $cmd = $config->workerCommand();

        $hasFlag = false;
        $count = count($cmd);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($cmd[$i] === '-d' && str_contains($cmd[$i + 1], 'swoole')) {
                $hasFlag = true;
                break;
            }
        }

        $iniConfigured = self::extensionInIniFiles('swoole');

        if ($iniConfigured) {
            self::assertFalse($hasFlag, 'INI-configured swoole must not get a -d extension flag (double-load poisons stdout)');
        } else {
            self::assertTrue($hasFlag, 'Non-INI swoole must get a -d extension flag for the child process');
        }
    }

    #[Test]
    public function detectFindsWorkerScriptAndAutoloadFromCurrentPackageLayout(): void
    {
        $config = ProcessConfig::detect();

        self::assertStringEndsWith('/src/Worker/bin/phalanx-worker', $config->workerScript);
        self::assertStringEndsWith('/vendor/autoload.php', $config->autoloadPath);
        self::assertFileExists($config->workerScript);
        self::assertFileExists($config->autoloadPath);
    }

    private static function extensionInIniFiles(string $extension): bool
    {
        $files = [];

        $loaded = php_ini_loaded_file();
        if ($loaded !== false) {
            $files[] = $loaded;
        }

        $scanned = php_ini_scanned_files();
        if ($scanned !== false && $scanned !== '') {
            foreach (explode(',', $scanned) as $file) {
                $file = trim($file);
                if ($file !== '') {
                    $files[] = $file;
                }
            }
        }

        $pattern = '/^\s*extension\s*=\s*[^;]*?' . preg_quote($extension, '/') . '/mi';

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (preg_match($pattern, FixtureFile::read($file))) {
                return true;
            }
        }

        return false;
    }
}

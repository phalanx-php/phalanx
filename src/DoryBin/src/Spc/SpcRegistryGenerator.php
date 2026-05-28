<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Spc;

use RuntimeException;

final class SpcRegistryGenerator
{
    public function generate(SpcBuildContext $context): void
    {
        $registryDir = $context->registryPath;
        $toolsDir = $context->workspaceRoot . '/tools/spc-swoole';

        self::ensureDir($registryDir);
        self::ensureDir($registryDir . '/config/pkg/ext');
        self::ensureDir($registryDir . '/src/Extension');

        self::writeRegistryYaml($registryDir);
        self::copyExtConfig($toolsDir, $registryDir);
        self::copyExtensionSource($toolsDir, $registryDir);
    }

    private static function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }
    }

    private static function writeRegistryYaml(string $registryDir): void
    {
        $yaml = <<<YAML
            name: dory-build-registry

            package:
              config:
                - config/pkg/ext/
              psr-4:
                PhalanxSpc\\Extension: src/Extension/
            YAML;

        file_put_contents($registryDir . '/spc.registry.yml', $yaml . "\n");
    }

    private static function copyExtConfig(string $toolsDir, string $registryDir): void
    {
        $src = $toolsDir . '/config/pkg/ext/ext-swoole.yml';
        $dst = $registryDir . '/config/pkg/ext/ext-swoole.yml';

        if (!is_file($src)) {
            throw new RuntimeException("Missing ext-swoole.yml at: {$src}");
        }

        copy($src, $dst);
    }

    private static function copyExtensionSource(string $toolsDir, string $registryDir): void
    {
        $src = $toolsDir . '/src/Extension/swoole.php';
        $dst = $registryDir . '/src/Extension/swoole.php';

        if (!is_file($src)) {
            throw new RuntimeException("Missing swoole.php extension source at: {$src}");
        }

        copy($src, $dst);
    }
}

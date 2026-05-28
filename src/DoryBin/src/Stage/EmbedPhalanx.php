<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Stage;

use Phalanx\DoryBin\Filesystem;
use Phalanx\DoryBin\Pipeline\BuildStage;
use Phalanx\DoryBin\Pipeline\StageResult;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class EmbedPhalanx implements BuildStage
{
    private(set) string $name = 'embed-phalanx';

    private(set) string $description = 'Embed Phalanx sources into binary';

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult
    {
        $start = hrtime(true);

        $rawBinary = $context->buildRoot . '/buildroot/bin/php';

        if (!is_file($rawBinary)) {
            return new StageResult(
                stageName: $this->name,
                success: false,
                skipped: false,
                durationMs: 0.0,
                summary: 'Raw PHP binary not found at ' . $rawBinary,
            );
        }

        $pharDir = $context->buildRoot . '/phar-staging';
        $classMap = self::collectSources($context->workspaceRoot, $pharDir, $context);

        self::writeAutoloader($pharDir, $classMap);

        $pharPath = $context->buildRoot . '/dory-embed.phar';
        self::buildPhar($pharDir, $pharPath);

        self::concatenate($rawBinary, $pharPath, $context->outputPath);

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $size = sprintf('%.1f MB', filesize($context->outputPath) / 1_048_576);

        return new StageResult(
            stageName: $this->name,
            success: true,
            skipped: false,
            durationMs: $durationMs,
            summary: "Binary with embedded sources: {$size}",
        );
    }

    public function canSkip(SpcBuildContext $context): bool
    {
        return false;
    }

    /**
     * Collect PHP sources from each profile package into the PHAR staging directory.
     *
     * @return array<string, string> classMap mapping FQCN => relative path inside PHAR
     */
    private static function collectSources(string $workspaceRoot, string $pharDir, SpcBuildContext $context): array
    {
        if (is_dir($pharDir)) {
            Filesystem::removeDir($pharDir);
        }

        mkdir($pharDir, 0755, true);
        mkdir($pharDir . '/vendor', 0755, true);

        $classMap = [];

        foreach ($context->profile->phalanxPackages as $package) {
            $packageSrcDir = self::findPackageSource($workspaceRoot, $package);

            if ($packageSrcDir === null) {
                continue;
            }

            self::copyPhpFiles($packageSrcDir, $pharDir, $package, $classMap);
        }

        // Include the bin/dory entry script so the PHAR stub can locate it.
        $doryBin = $workspaceRoot . '/src/Dory/bin/dory';

        if (is_file($doryBin)) {
            mkdir($pharDir . '/bin', 0755, true);
            copy($doryBin, $pharDir . '/bin/dory');
        }

        return $classMap;
    }

    /**
     * Resolve the src/ directory for a named Phalanx package.
     *
     * Package names in profiles are lowercase (e.g. "aegis", "stoa").
     * Directory layout in the monorepo: src/<PascalName>/src/.
     */
    private static function findPackageSource(string $workspaceRoot, string $package): ?string
    {
        $ucPackage = ucfirst($package);

        $candidates = [
            $workspaceRoot . '/src/' . $ucPackage . '/src',
            $workspaceRoot . '/packages/phalanx-' . $package . '/src',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Copy PHP files from a package source directory into the PHAR staging directory,
     * building a FQCN → relative-path classmap entry for each file.
     *
     * @param array<string, string> $classMap
     */
    private static function copyPhpFiles(
        string $srcDir,
        string $pharDir,
        string $package,
        array &$classMap,
    ): void {
        $ucPackage = ucfirst($package);
        $targetBase = $pharDir . '/src/' . $ucPackage;

        if (!is_dir($targetBase)) {
            mkdir($targetBase, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($srcDir));
            $targetPath = $targetBase . $relativePath;
            $targetSubDir = dirname($targetPath);

            if (!is_dir($targetSubDir)) {
                mkdir($targetSubDir, 0755, true);
            }

            $content = (string) file_get_contents($file->getPathname());
            file_put_contents($targetPath, $content);

            $namespace = self::extractNamespace($content);
            $className = self::extractClassName($content);

            if ($namespace !== null && $className !== null) {
                $fqcn = $namespace . '\\' . $className;
                $classMap[$fqcn] = 'src/' . $ucPackage . $relativePath;
            }
        }
    }

    private static function extractNamespace(string $content): ?string
    {
        if (preg_match('/^namespace\s+([^;\s{]+)/m', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function extractClassName(string $content): ?string
    {
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Write a classmap-based autoloader to vendor/autoload.php inside the staging directory.
     *
     * The generated file uses __DIR__ so it works whether loaded from a PHAR
     * archive or from an unpacked staging tree.
     *
     * @param array<string, string> $classMap
     */
    private static function writeAutoloader(string $pharDir, array $classMap): void
    {
        $entries = [];

        foreach ($classMap as $fqcn => $path) {
            $escapedFqcn = addslashes($fqcn);
            $escapedPath = addslashes($path);
            $entries[] = "    '{$escapedFqcn}' => __DIR__ . '/../{$escapedPath}'";
        }

        $mapContent = implode(",\n", $entries);

        $autoloadContent = <<<PHP
            <?php

            declare(strict_types=1);

            \$classMap = [
            {$mapContent},
            ];

            spl_autoload_register(static function (string \$class) use (\$classMap): void {
                if (isset(\$classMap[\$class])) {
                    require \$classMap[\$class];
                }
            });
            PHP;

        file_put_contents($pharDir . '/vendor/autoload.php', $autoloadContent . "\n");
    }

    /**
     * Build a PHAR from the staging directory.
     *
     * The stub is a bare __HALT_COMPILER() so the binary concatenation approach
     * can locate the PHAR data by its trailing manifest signature.
     * Files are GZ-compressed to reduce the embedded size.
     *
     * Requires phar.readonly=0 in the executing php.ini.
     */
    private static function buildPhar(string $pharDir, string $pharPath): void
    {
        if (ini_get('phar.readonly')) {
            throw new \RuntimeException('phar.readonly must be disabled (set phar.readonly=0 in php.ini or run with -d phar.readonly=0)');
        }

        if (is_file($pharPath)) {
            unlink($pharPath);
        }

        $phar = new \Phar($pharPath);
        $phar->buildFromDirectory($pharDir);
        $phar->setStub("<?php __HALT_COMPILER();\n");
        $phar->compressFiles(\Phar::GZ);
    }

    /**
     * Concatenate the raw static binary with the PHAR archive to produce the
     * final self-contained dory binary.
     *
     * PHP's PHAR loader searches backwards from EOF for the __HALT_COMPILER()
     * signature, so appending a PHAR to any binary produces a valid executable
     * that the static PHP itself can open as a PHAR.
     */
    private static function concatenate(string $binaryPath, string $pharPath, string $outputPath): void
    {
        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Failed to open output file: ' . $outputPath);
        }

        foreach ([$binaryPath, $pharPath] as $source) {
            $in = fopen($source, 'rb');
            if ($in === false) {
                fclose($out);
                throw new \RuntimeException('Failed to open source file: ' . $source);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        chmod($outputPath, 0755);
    }
}

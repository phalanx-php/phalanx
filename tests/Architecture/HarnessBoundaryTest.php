<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class HarnessBoundaryTest extends TestCase
{
    #[Test]
    public function moduleMetadataKeepsHarnessBoundaryDirectional(): void
    {
        $modules = require dirname(__DIR__, 2) . '/modules.php';
        $theatronManifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Theatron/composer.json'),
            true,
        );

        self::assertArrayHasKey('Agora', $modules);
        self::assertArrayHasKey('Harness', $modules);
        self::assertArrayHasKey('Theatron', $modules);
        self::assertArrayHasKey('Surreal', $modules);
        self::assertIsArray($theatronManifest);

        self::assertSame('phalanx-php/agora', $modules['Agora']['package']);
        self::assertSame('phalanx-php/harness', $modules['Harness']['package']);
        self::assertSame('phalanx-php/theatron', $modules['Theatron']['package']);
        self::assertSame('library', $modules['Agora']['type']);
        self::assertSame('library', $modules['Harness']['type']);
        self::assertSame('library', $modules['Theatron']['type']);

        self::assertArrayHasKey('phalanx-php/surreal', $modules['Agora']['requires']);
        self::assertArrayHasKey('phalanx-php/agora', $modules['Harness']['requires']);
        self::assertArrayHasKey('phalanx-php/athena', $modules['Harness']['requires']);
        self::assertArrayHasKey('phalanx-php/panoply', $modules['Harness']['requires']);
        self::assertArrayHasKey('phalanx-php/surreal', $modules['Harness']['requires']);
        self::assertArrayHasKey('phalanx-php/theatron', $modules['Harness']['requires']);
        self::assertArrayNotHasKey('phalanx-php/harness', $modules['Agora']['requires']);
        self::assertArrayNotHasKey('phalanx-php/theatron', $modules['Agora']['requires']);
        self::assertArrayNotHasKey('phalanx-php/agora', $modules['Theatron']['requires']);
        self::assertArrayNotHasKey('phalanx-php/harness', $modules['Theatron']['requires']);
        self::assertArrayNotHasKey('phalanx-php/surreal', $modules['Theatron']['requires']);
        self::assertArrayNotHasKey('phalanx-php/surreal', $theatronManifest['require']);
    }

    #[Test]
    public function agoraDoesNotOwnTheatronOrHarnessUiComposition(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Agora/src') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            foreach (['Phalanx\\Theatron\\', 'Phalanx\\Harness\\'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Agora must stay durable harness state/replay infrastructure; Harness owns UI composition:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function theatronDoesNotDependOnAgoraHarnessOrSurrealPersistence(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Theatron/src') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            foreach (['Phalanx\\Agora\\', 'Phalanx\\Athena\\', 'Phalanx\\Harness\\', 'Phalanx\\Panoply\\', 'Phalanx\\Surreal\\', 'phalanx-php/harness'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Theatron must stay a generic TUI layer, not the Harness app shell:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function harnessOwnsTheAppShellAndReplayHydration(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryExists($root . '/src/Harness/src/Agent');
        self::assertDirectoryExists($root . '/src/Harness/src/Template');
        self::assertFileExists($root . '/src/Harness/src/Replay/TheatronReplayHydrator.php');
        self::assertDirectoryDoesNotExist($root . '/src/Theatron/src/Agent');
        self::assertDirectoryDoesNotExist($root . '/src/Theatron/src/Template');
        self::assertFileDoesNotExist($root . '/src/Agora/src/Theatron/TheatronReplayHydrator.php');
    }

    #[Test]
    public function harnessBinCanFindTheMonorepoRuntimeAutoloader(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = $root . '/src/Harness/bin/harness';
        $source = file_get_contents($bin);
        self::assertIsString($source);

        self::assertStringContainsString("dirname(__DIR__, 3) . '/vendor/autoload_runtime.php'", $source);
        self::assertFileExists(dirname($bin, 4) . '/vendor/autoload_runtime.php');
    }

    #[Test]
    public function harnessBinDelegatesToTheHarnessAppFactory(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Harness/bin/harness');
        self::assertIsString($source);

        self::assertStringContainsString('use Phalanx\\Harness\\Harness;', $source);
        self::assertStringContainsString('Harness::app($context)->run()', $source);
        self::assertStringNotContainsString('TemplateApp::store()', $source);
        self::assertStringNotContainsString('Theatron::app($context)', $source);
    }

    #[Test]
    public function harnessSourceUsesHarnessConfigKeys(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Harness/src') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            foreach (['THEATRON_OLLAMA', 'THEATRON_MAX_INVOCATIONS'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Harness runtime config must use HARNESS_* keys:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function rootReadmeDoesNotAdvertiseHarnessStarterCommandsBeforeBootProof(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme);

        self::assertStringNotContainsString('phalanx-php/harness', $readme);
        self::assertStringNotContainsString('bin/harness', $readme);
        self::assertStringNotContainsString('php bin/harness', $readme);
    }

    #[Test]
    public function harnessReadmeDoesNotAdvertiseStarterRunCommandBeforeBootProof(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/src/Harness/README.md');
        self::assertIsString($readme);

        self::assertStringContainsString('assets/banner.svg', $readme);
        self::assertStringNotContainsString('php bin/harness', $readme);
    }

    #[Test]
    public function theatronReadmeDoesNotDocumentMovedHarnessTemplateTypes(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/src/Theatron/README.md');
        self::assertIsString($readme);

        self::assertStringNotContainsString('Phalanx\\Theatron\\Template', $readme);
        self::assertStringNotContainsString('src/Theatron/bin/theatron', $readme);
        self::assertStringNotContainsString('bin/theatron', $readme);
        self::assertStringContainsString('phalanx-php/harness', $readme);
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $root, string $file): string
    {
        return str_replace($root . '/', '', $file);
    }
}

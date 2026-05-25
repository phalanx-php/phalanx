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

        self::assertArrayHasKey('Agora', $modules);
        self::assertArrayHasKey('Theatron', $modules);
        self::assertArrayHasKey('Surreal', $modules);

        self::assertSame('phalanx-php/agora', $modules['Agora']['package']);
        self::assertSame('phalanx-php/theatron', $modules['Theatron']['package']);
        self::assertSame('library', $modules['Agora']['type']);
        self::assertSame('library', $modules['Theatron']['type']);

        self::assertArrayHasKey('phalanx-php/theatron', $modules['Agora']['requires']);
        self::assertArrayHasKey('phalanx-php/surreal', $modules['Agora']['requires']);
        self::assertArrayNotHasKey('phalanx-php/agora', $modules['Theatron']['requires']);
        self::assertArrayNotHasKey('phalanx-php/harness', $modules['Theatron']['requires']);
    }

    #[Test]
    public function agoraTheatronReferencesStayInsideTheBridgeNamespace(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Agora/src') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            if (
                str_contains($source, 'Phalanx\\Theatron\\')
                && !str_starts_with($file, $root . '/src/Agora/src/Theatron/')
            ) {
                $offenders[] = self::relative($root, $file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Agora may bridge into Theatron only from src/Agora/src/Theatron:\n" . implode("\n", $offenders),
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

            foreach (['Phalanx\\Agora\\', 'Phalanx\\Harness\\', 'Phalanx\\Surreal\\', 'phalanx-php/harness'] as $token) {
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
    public function rootReadmeDoesNotAdvertiseHarnessStarterCommandsBeforeBootProof(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme);

        self::assertStringNotContainsString('phalanx-php/harness', $readme);
        self::assertStringNotContainsString('bin/harness', $readme);
        self::assertStringNotContainsString('php bin/harness', $readme);
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

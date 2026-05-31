<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class TheatronHarnessBoundaryTest extends TestCase
{
    #[Test]
    public function standaloneHarnessModuleIsRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        $modules = require $root . '/modules.php';

        self::assertArrayNotHasKey('Harness', $modules);
        self::assertDirectoryDoesNotExist($root . '/src/Harness');
        self::assertDirectoryExists($root . '/src/Theatron/src/Harness');
    }

    #[Test]
    public function packageMetadataDoesNotExposeStandaloneHarness(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = self::read($root . '/composer.json');
        $modules = self::read($root . '/modules.php');
        $phpstan = self::read($root . '/phpstan.neon');

        foreach (self::standaloneHarnessTokens() as $token) {
            self::assertStringNotContainsString($token, $composer);
            self::assertStringNotContainsString($token, $modules);
            self::assertStringNotContainsString($token, $phpstan);
        }
    }

    #[Test]
    public function docsAndDemosDoNotAdvertiseStandaloneHarness(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/demos/harness');

        foreach ([$root . '/README.md', $root . '/src/Theatron/README.md'] as $file) {
            $source = self::read($file);

            foreach (self::standaloneHarnessTokens() as $token) {
                self::assertStringNotContainsString($token, $source, self::relative($root, $file));
            }
        }
    }

    #[Test]
    public function theatronHarnessDoesNotPullInOldApplicationLayerDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Theatron/src/Harness') as $file) {
            $source = self::read($file);

            foreach (['Phalanx\\Agora\\', 'Phalanx\\Athena\\', 'Phalanx\\Panoply\\', 'Phalanx\\Surreal\\'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Theatron harness contracts must stay below agent execution, cue, replay, and persistence wiring:\n" . implode("\n", $offenders),
        );
    }

    /**
     * @return list<string>
     */
    private static function standaloneHarnessTokens(): array
    {
        return [
            'Phalanx\\\\Harness\\\\',
            'Phalanx\\Harness\\',
            'phalanx-php/harness',
            'src/Harness',
            'demos/harness',
            'demo:harness',
            'test:harness',
            'prove:harness-install',
            'Harness::app',
        ];
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

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    private static function relative(string $root, string $file): string
    {
        return str_replace($root . '/', '', $file);
    }
}

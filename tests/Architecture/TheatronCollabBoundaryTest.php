<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class TheatronCollabBoundaryTest extends TestCase
{
    #[Test]
    public function standaloneLegacyModuleIsRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        $modules = require $root . '/modules.php';

        self::assertArrayNotHasKey(self::legacyModuleName(), $modules);
        self::assertDirectoryDoesNotExist($root . '/src/' . self::legacyModuleName());
        self::assertDirectoryDoesNotExist($root . '/src/Theatron/src/' . self::legacyModuleName());
        self::assertDirectoryExists($root . '/src/Theatron/src/Collab');
    }

    #[Test]
    public function packageMetadataDoesNotExposeStandaloneLegacyModule(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = self::read($root . '/composer.json');
        $modules = self::read($root . '/modules.php');
        $phpstan = self::read($root . '/phpstan.neon');

        foreach (self::standaloneLegacyModuleTokens() as $token) {
            self::assertStringNotContainsString($token, $composer);
            self::assertStringNotContainsString($token, $modules);
            self::assertStringNotContainsString($token, $phpstan);
        }
    }

    #[Test]
    public function docsAndDemosDoNotAdvertiseStandaloneLegacyModule(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/demos/' . strtolower(self::legacyModuleName()));

        foreach ([$root . '/README.md', $root . '/src/Theatron/README.md'] as $file) {
            $source = self::read($file);

            foreach (self::standaloneLegacyModuleTokens() as $token) {
                self::assertStringNotContainsString($token, $source, self::relative($root, $file));
            }
        }
    }

    #[Test]
    public function collabDoesNotPullInOldApplicationLayerDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Theatron/src/Collab') as $file) {
            $source = self::read($file);

            foreach (['Phalanx\\Athena\\', 'Phalanx\\Panoply\\', 'Phalanx\\Surreal\\'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Collab contracts must stay below agent execution, cue, replay, and persistence wiring:\n" . implode("\n", $offenders),
        );
    }

    /**
     * @return list<string>
     */
    private static function standaloneLegacyModuleTokens(): array
    {
        $module = self::legacyModuleName();
        $lower = strtolower($module);

        return [
            'Phalanx\\\\' . $module . '\\\\',
            'Phalanx\\' . $module . '\\',
            'phalanx-php/' . $lower,
            'src/' . $module,
            'demos/' . $lower,
            'demo:' . $lower,
            'test:' . $lower,
            'prove:' . $lower . '-install',
            $module . '::app',
        ];
    }

    private static function legacyModuleName(): string
    {
        return 'Harness';
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

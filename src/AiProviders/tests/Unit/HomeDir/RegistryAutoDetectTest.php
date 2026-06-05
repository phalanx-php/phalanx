<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir;

use Phalanx\AiProviders\HomeDir\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Pins the autoDetect filesystem probe algorithm with synthetic home dirs. */
final class RegistryAutoDetectTest extends TestCase
{
    /** @var list<string> */
    private static array $fixtureHomes = [];

    #[Test]
    public function allThreeToolsDetectedInFullFixture(): void
    {
        $home = self::fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertCount(3, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertTrue($registry->has('gemini_cli'));
        self::assertTrue($registry->has('codex'));
    }

    #[Test]
    public function onlyClaudeDetectedInPartialFixture(): void
    {
        $home = self::fixtureHome('.claude');
        $registry = Registry::autoDetect($home);

        self::assertCount(1, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertFalse($registry->has('gemini_cli'));
        self::assertFalse($registry->has('codex'));
    }

    #[Test]
    public function noToolsDetectedInEmptyFixture(): void
    {
        $home = self::fixtureHome();
        $registry = Registry::autoDetect($home);

        self::assertCount(0, $registry->all());
    }

    #[Test]
    public function autoDetectWithNonExistentHomeReturnsEmpty(): void
    {
        $registry = Registry::autoDetect('/this/path/does/not/exist/at/all');

        self::assertCount(0, $registry->all());
    }

    #[Test]
    public function detectedAdaptersImplementHomeDirInterface(): void
    {
        $home = self::fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        foreach ($registry->all() as $adapter) {
            self::assertInstanceOf(\Phalanx\AiProviders\HomeDir::class, $adapter);
        }
    }

    #[Test]
    public function claudeCodeAdapterHasCorrectType(): void
    {
        $home = self::fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\ClaudeCode\HomeDir::class,
            $registry->get('claude_code'),
        );
    }

    #[Test]
    public function geminiCliAdapterHasCorrectType(): void
    {
        $home = self::fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\GeminiCli\HomeDir::class,
            $registry->get('gemini_cli'),
        );
    }

    #[Test]
    public function codexAdapterHasCorrectType(): void
    {
        $home = self::fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\Codex\HomeDir::class,
            $registry->get('codex'),
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$fixtureHomes as $home) {
            self::removeTree($home);
        }

        self::$fixtureHomes = [];
    }

    private static function fixtureHome(string ...$roots): string
    {
        $base = sys_get_temp_dir() . '/' . uniqid('ai-providers_home_', true);
        mkdir($base, 0777, true);

        foreach ($roots as $root) {
            mkdir($base . '/' . $root, 0777, true);
        }

        self::$fixtureHomes[] = $base;

        return $base;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

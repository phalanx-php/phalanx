<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir;

use Phalanx\Panoply\HomeDir\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the autoDetect filesystem probe algorithm. Uses synthetic fixture
 * directories under tests/Fixtures/HomeDir/autoDetect/ to simulate different
 * tool installation states.
 *
 * Fixture layout:
 *   full/    — has .claude/, .gemini/, .codex/ → expects 3 adapters
 *   partial/ — has .claude/ only              → expects 1 adapter
 *   none/    — empty                           → expects 0 adapters
 */
final class RegistryAutoDetectTest extends TestCase
{
    #[Test]
    public function allThreeToolsDetectedInFullFixture(): void
    {
        $home     = self::fixtureRoot() . '/full';
        $registry = Registry::autoDetect($home);

        self::assertCount(3, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertTrue($registry->has('gemini_cli'));
        self::assertTrue($registry->has('codex'));
    }

    #[Test]
    public function onlyClaudeDetectedInPartialFixture(): void
    {
        $home     = self::fixtureRoot() . '/partial';
        $registry = Registry::autoDetect($home);

        self::assertCount(1, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertFalse($registry->has('gemini_cli'));
        self::assertFalse($registry->has('codex'));
    }

    #[Test]
    public function noToolsDetectedInEmptyFixture(): void
    {
        $home     = self::fixtureRoot() . '/none';
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
        $home    = self::fixtureRoot() . '/full';
        $registry = Registry::autoDetect($home);

        foreach ($registry->all() as $adapter) {
            self::assertInstanceOf(\Phalanx\Panoply\HomeDir::class, $adapter);
        }
    }

    #[Test]
    public function claudeCodeAdapterHasCorrectType(): void
    {
        $home     = self::fixtureRoot() . '/full';
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\Panoply\HomeDir\ClaudeCode\HomeDir::class,
            $registry->get('claude_code'),
        );
    }

    #[Test]
    public function geminiCliAdapterHasCorrectType(): void
    {
        $home     = self::fixtureRoot() . '/full';
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\Panoply\HomeDir\GeminiCli\HomeDir::class,
            $registry->get('gemini_cli'),
        );
    }

    #[Test]
    public function codexAdapterHasCorrectType(): void
    {
        $home     = self::fixtureRoot() . '/full';
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\Panoply\HomeDir\Codex\HomeDir::class,
            $registry->get('codex'),
        );
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/HomeDir/autoDetect';
    }
}

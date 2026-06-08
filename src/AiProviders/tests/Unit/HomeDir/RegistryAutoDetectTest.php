<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir;

use Phalanx\AiProviders\HomeDir\Registry;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Pins the autoDetect filesystem probe algorithm with synthetic home dirs. */
final class RegistryAutoDetectTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function allThreeToolsDetectedInFullFixture(): void
    {
        $home = $this->fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertCount(3, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertTrue($registry->has('gemini_cli'));
        self::assertTrue($registry->has('codex'));
    }

    #[Test]
    public function onlyClaudeDetectedInPartialFixture(): void
    {
        $home = $this->fixtureHome('.claude');
        $registry = Registry::autoDetect($home);

        self::assertCount(1, $registry->all());
        self::assertTrue($registry->has('claude_code'));
        self::assertFalse($registry->has('gemini_cli'));
        self::assertFalse($registry->has('codex'));
    }

    #[Test]
    public function noToolsDetectedInEmptyFixture(): void
    {
        $home = $this->fixtureHome();
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
        $home = $this->fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        foreach ($registry->all() as $adapter) {
            self::assertInstanceOf(\Phalanx\AiProviders\HomeDir::class, $adapter);
        }
    }

    #[Test]
    public function claudeCodeAdapterHasCorrectType(): void
    {
        $home = $this->fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\ClaudeCode\HomeDir::class,
            $registry->get('claude_code'),
        );
    }

    #[Test]
    public function geminiCliAdapterHasCorrectType(): void
    {
        $home = $this->fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\GeminiCli\HomeDir::class,
            $registry->get('gemini_cli'),
        );
    }

    #[Test]
    public function codexAdapterHasCorrectType(): void
    {
        $home = $this->fixtureHome('.claude', '.gemini', '.codex');
        $registry = Registry::autoDetect($home);

        self::assertInstanceOf(
            \Phalanx\AiProviders\HomeDir\Codex\HomeDir::class,
            $registry->get('codex'),
        );
    }

    private function fixtureHome(string ...$roots): string
    {
        $base = $this->tempWorkspace('ai-providers-home-')->path();

        foreach ($roots as $root) {
            $this->tempWorkspace()->dir($root);
        }

        return $base;
    }
}

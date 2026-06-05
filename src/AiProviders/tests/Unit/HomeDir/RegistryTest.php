<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir;

use Phalanx\AiProviders\HomeDir\ClaudeCode\HomeDir as ClaudeCodeHomeDir;
use Phalanx\AiProviders\HomeDir\GeminiCli\HomeDir as GeminiCliHomeDir;
use Phalanx\AiProviders\HomeDir\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the immutable Registry contract — mirrors Agent\Registry behavior.
 */
final class RegistryTest extends TestCase
{
    #[Test]
    public function emptyRegistryHasNoEntries(): void
    {
        $registry = Registry::empty();

        self::assertSame([], $registry->all());
    }

    #[Test]
    public function withAddsEntry(): void
    {
        $registry = Registry::empty()
            ->with('claude_code', new ClaudeCodeHomeDir('/fake/path'));

        self::assertTrue($registry->has('claude_code'));
    }

    #[Test]
    public function withIsImmutable(): void
    {
        $original = Registry::empty();
        $updated = $original->with('claude_code', new ClaudeCodeHomeDir('/fake/path'));

        self::assertFalse($original->has('claude_code'));
        self::assertTrue($updated->has('claude_code'));
    }

    #[Test]
    public function getReturnsHomeDirByKey(): void
    {
        $homeDir = new ClaudeCodeHomeDir('/fake/path');
        $registry = Registry::empty()->with('claude_code', $homeDir);

        self::assertSame($homeDir, $registry->get('claude_code'));
    }

    #[Test]
    public function getReturnsNullForUnknownKey(): void
    {
        $registry = Registry::empty();

        self::assertNull($registry->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownKey(): void
    {
        $registry = Registry::empty();

        self::assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function allReturnsAllRegisteredAdapters(): void
    {
        $claude = new ClaudeCodeHomeDir('/claude');
        $gemini = new GeminiCliHomeDir('/gemini');

        $registry = Registry::empty()
            ->with('claude_code', $claude)
            ->with('gemini_cli', $gemini);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertSame($claude, $all['claude_code']);
        self::assertSame($gemini, $all['gemini_cli']);
    }

    #[Test]
    public function withUpserts(): void
    {
        $original = new ClaudeCodeHomeDir('/first');
        $updated = new ClaudeCodeHomeDir('/second');

        $registry = Registry::empty()
            ->with('claude_code', $original)
            ->with('claude_code', $updated);

        self::assertSame($updated, $registry->get('claude_code'));
        self::assertCount(1, $registry->all());
    }
}

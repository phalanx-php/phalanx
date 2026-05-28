<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Slice\AgentEntry;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentRosterSliceTest extends TestCase
{
    #[Test]
    public function with_status_updates_known_agent(): void
    {
        $roster = new AgentRosterSlice([
            'a' => new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama'),
        ]);

        $updated = $roster->withStatus('a', 'thinking');

        self::assertSame('thinking', $updated->agents['a']->status);
        self::assertSame('idle', $roster->agents['a']->status);
    }

    #[Test]
    public function with_status_ignores_unknown_agent(): void
    {
        $roster = new AgentRosterSlice([
            'a' => new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama'),
        ]);

        $updated = $roster->withStatus('nonexistent', 'thinking');

        self::assertCount(1, $updated->agents);
        self::assertSame('idle', $updated->agents['a']->status);
    }

    #[Test]
    public function with_tokens_adds_to_existing(): void
    {
        $roster = new AgentRosterSlice([
            'a' => new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama', tokens: 10),
        ]);

        $updated = $roster->withTokens('a', 5);

        self::assertSame(15, $updated->agents['a']->tokens);
        self::assertSame(10, $roster->agents['a']->tokens);
    }

    #[Test]
    public function with_tokens_ignores_unknown_agent(): void
    {
        $roster = new AgentRosterSlice([]);
        $updated = $roster->withTokens('nonexistent', 5);

        self::assertSame([], $updated->agents);
    }

    #[Test]
    public function slice_key_is_showcase_roster(): void
    {
        self::assertSame('showcase.roster', (new AgentRosterSlice())->key);
    }
}

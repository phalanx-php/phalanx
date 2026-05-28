<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Slice\AgentEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentEntryTest extends TestCase
{
    #[Test]
    public function defaults_to_idle_zero_tokens(): void
    {
        $entry = new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama');

        self::assertSame('idle', $entry->status);
        self::assertSame(0, $entry->tokens);
    }

    #[Test]
    public function with_status_returns_new_instance(): void
    {
        $entry = new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama');
        $updated = $entry->withStatus('thinking');

        self::assertSame('thinking', $updated->status);
        self::assertSame('idle', $entry->status);
        self::assertSame('Thales', $updated->name);
    }

    #[Test]
    public function with_tokens_returns_new_instance(): void
    {
        $entry = new AgentEntry(id: 'a', name: 'Thales', role: 'researcher', provider: 'ollama');
        $updated = $entry->withTokens(42);

        self::assertSame(42, $updated->tokens);
        self::assertSame(0, $entry->tokens);
    }
}

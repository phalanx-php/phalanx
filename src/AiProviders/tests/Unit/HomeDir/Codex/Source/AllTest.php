<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\Codex\Source;

use Phalanx\AiProviders\HomeDir\Codex\Source\All;
use Phalanx\AiProviders\HomeDir\Codex\Source\History;
use Phalanx\AiProviders\HomeDir\Codex\Source\Sessions;
use Phalanx\AiProviders\HomeDir\Codex\Source\Sqlite;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Source\All value-object contract: available sources are determined
 * at construction time based on which sources are non-null. The actual merge
 * behavior is tested via Codex\ParserTest which drives the All source through
 * the Parser.
 */
final class AllTest extends TestCase
{
    #[Test]
    public function constructsWithAllThreeSourcesNull(): void
    {
        $all = new All(sessions: null, history: null, sqlite: null);

        self::assertNull($all->sessions);
        self::assertNull($all->history);
        self::assertNull($all->sqlite);
    }

    #[Test]
    public function constructsWithAllThreeSourcesPresent(): void
    {
        $sessions = new Sessions('/sessions');
        $history = new History('/history.jsonl');
        $sqlite = new Sqlite('/logs.sqlite');

        $all = new All(sessions: $sessions, history: $history, sqlite: $sqlite);

        self::assertSame($sessions, $all->sessions);
        self::assertSame($history, $all->history);
        self::assertSame($sqlite, $all->sqlite);
    }

    #[Test]
    public function configuredSourcesIsEmptyWhenAllNull(): void
    {
        $all = new All(sessions: null, history: null, sqlite: null);

        self::assertSame([], $all->configuredSources());
    }

    #[Test]
    public function configuredSourcesReflectsConfiguredSourcesAtConstruction(): void
    {
        $all = new All(
            sessions: new Sessions('/s'),
            history: null,
            sqlite: null,
        );

        self::assertSame(['sessions'], $all->configuredSources());
    }

    #[Test]
    public function configuredSourcesContainsAllThreeWhenAllPresent(): void
    {
        $all = new All(
            sessions: new Sessions('/s'),
            history: new History('/h.jsonl'),
            sqlite: new Sqlite('/db.sqlite'),
        );

        self::assertContains('sessions', $all->configuredSources());
        self::assertContains('history', $all->configuredSources());
        self::assertContains('sqlite', $all->configuredSources());
        self::assertCount(3, $all->configuredSources());
    }

    #[Test]
    public function configuredSourcesExcludesNullSources(): void
    {
        $all = new All(
            sessions: new Sessions('/s'),
            history: new History('/h.jsonl'),
            sqlite: null,
        );

        self::assertContains('sessions', $all->configuredSources());
        self::assertContains('history', $all->configuredSources());
        self::assertNotContains('sqlite', $all->configuredSources());
        self::assertCount(2, $all->configuredSources());
    }

    #[Test]
    public function configuredSourcesIsImmutableAfterConstruction(): void
    {
        // configuredSources is computed once at construction — reading it twice
        // returns the same value and there is no public mutator.
        $all = new All(sessions: new Sessions('/s'), history: null, sqlite: null);

        $first = $all->configuredSources();
        $second = $all->configuredSources();

        self::assertSame($first, $second);
    }

    #[Test]
    public function sourcesHoldCorrectTypes(): void
    {
        $sessions = new Sessions('/some/sessions');
        $history = new History('/some/history.jsonl');

        $all = new All(sessions: $sessions, history: $history, sqlite: null);

        self::assertInstanceOf(Sessions::class, $all->sessions);
        self::assertInstanceOf(History::class, $all->history);
        self::assertNull($all->sqlite);
    }
}

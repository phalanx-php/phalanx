<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\Codex;

use Phalanx\AiProviders\HomeDir\Codex\HomeDir;
use Phalanx\AiProviders\HomeDir\Codex\Parser;
use Phalanx\AiProviders\HomeDir\Codex\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Surface tests for the Codex HomeDir adapter against the fixture directory
 * at tests/Fixtures/HomeDir/Codex/.
 */
final class HomeDirTest extends TestCase
{
    #[Test]
    public function parserReturnsParserInstance(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());

        self::assertInstanceOf(Parser::class, $homeDir->parser());
    }

    #[Test]
    public function settingsReturnsSettingsInstance(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());

        self::assertInstanceOf(Settings::class, $homeDir->settings());
    }

    #[Test]
    public function locatorsYieldsSessionsDirWhenPresent(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $locators = $homeDir->locators()->toArray();

        $paths = array_map(static fn ($l): string => $l->path, $locators);
        self::assertContains(self::fixtureRoot() . '/sessions', $paths);
    }

    #[Test]
    public function locatorsYieldsHistoryJsonlWhenPresent(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $locators = $homeDir->locators()->toArray();

        $paths = array_map(static fn ($l): string => $l->path, $locators);
        self::assertContains(self::fixtureRoot() . '/history.jsonl', $paths);
    }

    #[Test]
    public function projectsDerivedFromSessionsTreeCwdValues(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        // Both session files declare cwd=/srv/phalanx/agora so there should
        // be exactly one distinct project.
        self::assertCount(1, $projects);
    }

    #[Test]
    public function projectHasCorrectPath(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        self::assertSame('/srv/phalanx/agora', $projects[0]->path);
    }

    #[Test]
    public function projectConversationCountEqualsSessionFileCount(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        // sessions/2026/05-17/ contains abc.jsonl and def.jsonl
        self::assertSame(2, $projects[0]->conversationCount);
    }

    #[Test]
    public function fromConfigResolvesHomeDirPath(): void
    {
        $adapterClass = str_replace('\\', '\\\\', \Phalanx\AiProviders\HomeDir\Codex\HomeDir::class);
        $yaml = "id: codex\ndisplay_name: \"Codex\"\nroots:\n  - \"~/.codex\"\nadapter: \"{$adapterClass}\"\n";
        $config = \Phalanx\AiProviders\HomeDir\Loader::fromString($yaml);

        $homeDir = HomeDir::fromConfig($config, '/fake/home');

        self::assertInstanceOf(HomeDir::class, $homeDir);
    }

    #[Test]
    public function emptyHomeDirProducesNoProjects(): void
    {
        $homeDir = new HomeDir('/does/not/exist');
        $projects = $homeDir->projects()->toArray();

        self::assertCount(0, $projects);
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/Codex';
    }
}

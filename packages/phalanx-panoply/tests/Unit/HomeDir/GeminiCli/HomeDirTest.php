<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\GeminiCli;

use Phalanx\Panoply\HomeDir\GeminiCli\HomeDir;
use Phalanx\Panoply\HomeDir\GeminiCli\Parser;
use Phalanx\Panoply\HomeDir\GeminiCli\Settings;
use Phalanx\Panoply\HomeDir\Project;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Surface tests for the Gemini CLI HomeDir adapter against the fixture
 * directory at tests/Fixtures/HomeDir/GeminiCli/.
 */
final class HomeDirTest extends TestCase
{
    #[Test]
    public function projectsReturnsProjectsFromIndex(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        self::assertCount(2, $projects);
        self::assertContainsOnlyInstancesOf(Project::class, $projects);
    }

    #[Test]
    public function projectsHaveCorrectPaths(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $paths    = array_map(static fn (Project $p): string => $p->path, $homeDir->projects()->toArray());

        self::assertContains('/srv/phalanx/marathon', $paths);
        self::assertContains('/srv/phalanx/olympus', $paths);
    }

    #[Test]
    public function projectsHaveCorrectHomeDirId(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        foreach ($projects as $project) {
            self::assertSame('gemini_cli', $project->homeDirId);
        }
    }

    #[Test]
    public function projectMarathonCountsHistoryFiles(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $bySlug   = [];

        foreach ($homeDir->projects() as $project) {
            $bySlug[$project->slug] = $project;
        }

        // proj-marathon has one JSONL file in history/proj-marathon/.
        self::assertArrayHasKey('proj-marathon', $bySlug);
        self::assertSame(1, $bySlug['proj-marathon']->conversationCount);
    }

    #[Test]
    public function projectLastActiveIsPopulated(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        $marathon = array_values(array_filter($projects, static fn (Project $p): bool => $p->slug === 'proj-marathon'));
        self::assertNotEmpty($marathon);
        self::assertInstanceOf(\DateTimeImmutable::class, $marathon[0]->lastActive);
    }

    #[Test]
    public function locatorsYieldsHomeDirDirectory(): void
    {
        $homeDir  = new HomeDir(self::fixtureRoot());
        $locators = $homeDir->locators()->toArray();

        self::assertGreaterThanOrEqual(1, count($locators));
        self::assertTrue($locators[0]->isDirectory);
    }

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
    public function noProjectsJsonProducesEmptyProjects(): void
    {
        $homeDir  = new HomeDir('/does/not/exist');
        $projects = $homeDir->projects()->toArray();

        self::assertCount(0, $projects);
    }

    #[Test]
    public function fromConfigResolvesHomeDirPath(): void
    {
        $adapterClass = str_replace('\\', '\\\\', \Phalanx\Panoply\HomeDir\GeminiCli\HomeDir::class);
        $yaml         = implode("\n", [
            'id: gemini_cli',
            'display_name: "Gemini CLI"',
            'roots:',
            '  - "~/.gemini"',
            "adapter: \"{$adapterClass}\"",
            '',
        ]);
        $config = \Phalanx\Panoply\HomeDir\Loader::fromString($yaml);

        $homeDir = HomeDir::fromConfig($config, '/fake/home');

        self::assertInstanceOf(HomeDir::class, $homeDir);
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/GeminiCli';
    }
}

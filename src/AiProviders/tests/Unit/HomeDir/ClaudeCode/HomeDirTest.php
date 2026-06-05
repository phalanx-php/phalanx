<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\HomeDir\ClaudeCode;

use Phalanx\AiProviders\HomeDir\ClaudeCode\HomeDir;
use Phalanx\AiProviders\HomeDir\ClaudeCode\Parser;
use Phalanx\AiProviders\HomeDir\ClaudeCode\Settings;
use Phalanx\AiProviders\HomeDir\Project;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Surface tests for the Claude Code HomeDir adapter against the fixture
 * directory at tests/Fixtures/HomeDir/ClaudeCode/.
 */
final class HomeDirTest extends TestCase
{
    #[Test]
    public function projectsReturnsAllProjectDirectories(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $projects = $homeDir->projects()->toArray();

        self::assertCount(2, $projects);
        self::assertContainsOnlyInstancesOf(Project::class, $projects);
    }

    #[Test]
    public function projectsHaveCorrectSlugs(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $slugs = array_map(static fn (Project $p): string => $p->slug, $homeDir->projects()->toArray());

        self::assertContains('-Users-jhavens-sparta', $slugs);
        self::assertContains('-Users-jhavens-marathon', $slugs);
    }

    #[Test]
    public function projectsDecodePathFromSlug(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $bySlug = [];

        foreach ($homeDir->projects() as $project) {
            $bySlug[$project->slug] = $project;
        }

        self::assertArrayHasKey('-Users-jhavens-sparta', $bySlug);
        // Decoded path: replace `-` with `/` — note this is lossy for paths
        // with literal hyphens, which is documented on Slug.
        self::assertStringContainsString('Users', $bySlug['-Users-jhavens-sparta']->path);
    }

    #[Test]
    public function projectsReportConversationCount(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $bySlug = [];

        foreach ($homeDir->projects() as $project) {
            $bySlug[$project->slug] = $project;
        }

        self::assertSame(1, $bySlug['-Users-jhavens-sparta']->conversationCount);
        self::assertSame(1, $bySlug['-Users-jhavens-marathon']->conversationCount);
    }

    #[Test]
    public function locatorsYieldsHomeDirLocator(): void
    {
        $homeDir = new HomeDir(self::fixtureRoot());
        $locators = $homeDir->locators()->toArray();

        self::assertGreaterThanOrEqual(1, count($locators));
        self::assertTrue($locators[0]->isDirectory);
    }

    #[Test]
    public function locatorsYieldsSidecarWhenPresent(): void
    {
        $fixtureRoot = self::fixtureRoot();
        $homeDir = new HomeDir($fixtureRoot, $fixtureRoot . '/claude.json');
        $locators = $homeDir->locators()->toArray();

        // Should have both the directory and the sidecar file.
        self::assertCount(2, $locators);
        $paths = array_map(static fn ($l): string => $l->path, $locators);
        self::assertContains($fixtureRoot . '/claude.json', $paths);
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
    public function fromConfigResolvesTome(): void
    {
        $config = \Phalanx\AiProviders\HomeDir\Loader::fromFile(
            dirname(__DIR__, 3) . '/Fixtures/HomeDir/claudecode.ai-providers.yaml',
        );
        $homeDir = HomeDir::fromConfig($config, '/fake/home');

        // homeDirPath should be /fake/home/.claude
        self::assertInstanceOf(HomeDir::class, $homeDir);
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/ClaudeCode';
    }
}

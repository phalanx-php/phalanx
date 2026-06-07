<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Testing\Lenses;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Config\Env;
use Phalanx\Config\Issue;
use Phalanx\Config\IssueLevel;
use Phalanx\Config\ValidationContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\Lenses\ConfigLens;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConfigLensTest extends PhalanxTestCase
{
    #[Test]
    public function lensIsRuntimeNative(): void
    {
        self::assertInstanceOf(ConfigLens::class, $this->testApp()->config);
    }

    #[Test]
    public function hydrateUsesApplicationContextValues(): void
    {
        $app = $this->testApp([
            'CONFIG_LENS_NAME' => 'sarissa',
            'CONFIG_LENS_COUNT' => '7',
        ], new ConfigLensBundle());

        $expectation = $app->config->hydrate(ConfigLensFixtureConfig::class)
            ->assertConfigured();

        self::assertInstanceOf(ConfigLensFixtureConfig::class, $expectation->config);
        self::assertSame('sarissa', $expectation->config->name);
        self::assertSame(7, $expectation->config->count);
    }

    #[Test]
    public function validateCanUseExplicitRoot(): void
    {
        $app = $this->testApp([
            'CONFIG_LENS_NAME' => 'sarissa',
            'CONFIG_LENS_COUNT' => '7',
        ], new ConfigLensBundle());

        $app->config->validate(ConfigLensFixtureConfig::class)
            ->assertClean();
    }

    #[Test]
    public function validateDefaultsToCatalogRoots(): void
    {
        $app = $this->testApp([
            'CONFIG_LENS_NAME' => 'sarissa',
            'CONFIG_LENS_COUNT' => '7',
        ], new ConfigLensBundle());

        $app->config->validate()
            ->assertClean();
    }

    #[Test]
    public function validateReportsMissingRequiredValues(): void
    {
        $app = $this->testApp();

        $expectation = $app->config->validate(ConfigLensRequiredConfig::class)
            ->assertHasErrors()
            ->assertBlocksBoot();

        self::assertSame(IssueLevel::Error, $expectation->result->issues[0]->level);
        self::assertSame('CONFIG_LENS_REQUIRED', $expectation->result->issues[0]->envKey);
    }

    #[Test]
    public function validateReportsWarnings(): void
    {
        $app = $this->testApp([], new ConfigLensWarningBundle());

        $app->config->validate()
            ->assertHasWarnings()
            ->assertDoesNotBlockBoot();
    }

    #[Test]
    public function validateWithPassesValidationContextThroughApplicationScope(): void
    {
        $app = $this->testApp([], new ConfigLensWarningBundle());

        $app->config->validateWith(new ValidationContext(strict: true))
            ->assertHasWarnings()
            ->assertBlocksBoot();
    }

    #[Test]
    public function catalogReflectsBundleConfigs(): void
    {
        $app = $this->testApp([], new ConfigLensBundle());

        $app->config->catalog()
            ->assertContains(ConfigLensFixtureConfig::class)
            ->assertNotContains(ConfigLensRequiredConfig::class)
            ->assertNotContains(ConfigLensWarningConfig::class);
    }

    #[Test]
    public function resetIsNoop(): void
    {
        $app = $this->testApp([], new ConfigLensBundle());

        $app->config->reset();

        $this->addToAssertionCount(1);
    }
}

final class ConfigLensFixtureConfig implements Config
{
    public bool $configured {
        get => $this->name !== '' && $this->count > 0;
    }

    public function __construct(
        #[Env(key: 'CONFIG_LENS_NAME', description: 'Fixture name')]
        public string $name = 'default',

        #[Env(key: 'CONFIG_LENS_COUNT', description: 'Fixture count')]
        public int $count = 1,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class ConfigLensRequiredConfig implements Config
{
    public bool $configured {
        get => $this->required !== '';
    }

    public function __construct(
        #[Env(key: 'CONFIG_LENS_REQUIRED', description: 'Required fixture value')]
        public string $required,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class ConfigLensWarningConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function validate(ValidationContext $context): array
    {
        return [
            Issue::warning(
                code: 'config-lens.warning',
                message: 'Config lens warning fixture.',
            ),
        ];
    }
}

final class ConfigLensBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [ConfigLensFixtureConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class ConfigLensWarningBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [ConfigLensWarningConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Config;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Config\ConfigHydrationException;
use Phalanx\Config\ConfigHydrator;
use Phalanx\Config\ConfigReflection;
use Phalanx\Config\Env;
use Phalanx\Config\EnvExampleGenerator;
use Phalanx\Config\Issue;
use Phalanx\Config\IssueLevel;
use Phalanx\Config\Secret;
use Phalanx\Config\ValidationContext;
use Phalanx\Config\ValidationReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigHydratorTest extends TestCase
{
    #[Test]
    public function hydrateBuildsTypedConfigFromEnvAttributes(): void
    {
        $config = ConfigHydrator::from(new AppContext([
            'TEST_NAME' => 'apollo',
            'TEST_ENABLED' => 'true',
            'TEST_LIMIT' => '7',
            'TEST_TOKEN' => 'secret-token',
        ]))->hydrate(ConfigHydratorFixture::class);

        self::assertSame('apollo', $config->name);
        self::assertTrue($config->enabled);
        self::assertSame(7, $config->limit);
        self::assertTrue($config->token->configured);
        self::assertSame('secret-token', $config->token->reveal());
        self::assertSame('[redacted]', (string) $config->token);
    }

    #[Test]
    public function missingSecretHydratesAsEmptySecret(): void
    {
        $config = ConfigHydrator::from(new AppContext(['TEST_TOKEN' => null]))
            ->hydrate(ConfigHydratorFixture::class);

        self::assertFalse($config->token->configured);
    }

    #[Test]
    public function invalidScalarProducesIssue(): void
    {
        try {
            ConfigHydrator::from(new AppContext(['TEST_LIMIT' => 'many']))
                ->hydrate(ConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-int', $exception->issues[0]->code);
            self::assertSame('TEST_LIMIT', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Invalid integer env value should produce a hydration exception.');
    }

    #[Test]
    public function hydrateSupportsBackedEnums(): void
    {
        $config = ConfigHydrator::from(new AppContext(['TEST_MODE' => 'slow']))
            ->hydrate(EnumConfigHydratorFixture::class);

        self::assertSame(ConfigHydratorFixtureMode::Slow, $config->mode);
    }

    #[Test]
    public function hydrateBuildsNestedConfigObjects(): void
    {
        $config = ConfigHydrator::from(new AppContext(['TEST_NAME' => 'nested']))
            ->hydrate(NestedConfigHydratorFixture::class);

        self::assertSame('nested', $config->inner->name);
    }

    #[Test]
    public function tryHydrateReturnsValidationIssuesWithoutStoringStateOnConfig(): void
    {
        $result = ConfigHydrator::from(new AppContext())
            ->tryHydrate(ValidatingConfigHydratorFixture::class);

        self::assertInstanceOf(ValidatingConfigHydratorFixture::class, $result->config);
        self::assertSame('fixture.warning', $result->issues[0]->code);
    }

    #[Test]
    public function validationReportBlocksBootOnlyForErrorsOrStrictWarnings(): void
    {
        $config = new ValidatingConfigHydratorFixture();
        $issues = $config->validate(new ValidationContext());

        $relaxed = new ValidationReport($config, new ValidationContext(strict: false), $issues);
        $strict = new ValidationReport($config, new ValidationContext(strict: true), $issues);

        self::assertFalse($relaxed->blocksBoot);
        self::assertTrue($relaxed->hasWarnings);
        self::assertTrue($relaxed->valid);
        self::assertTrue($strict->blocksBoot);
    }

    #[Test]
    public function reflectionListsEnvKeysWithoutHydratingValues(): void
    {
        $definitions = (new ConfigReflection())->describe(ConfigHydratorFixture::class);

        self::assertCount(1, $definitions);
        self::assertSame(
            ['TEST_NAME', 'TEST_ENABLED', 'TEST_LIMIT', 'TEST_TOKEN'],
            array_map(static fn($entry): string => $entry->envKey, $definitions[0]->entries),
        );
        self::assertTrue($definitions[0]->entries[3]->secret);
    }

    #[Test]
    public function envExampleIsSchemaOrderedAndPreservesCustomValues(): void
    {
        $example = (new EnvExampleGenerator())->generate(
            [ConfigHydratorFixture::class],
            [
                'CUSTOM_VALUE' => 'kept',
                'TEST_LIMIT' => '9',
            ],
        );

        self::assertStringContainsString("TEST_NAME=default-name\nTEST_ENABLED=false\nTEST_LIMIT=9\nTEST_TOKEN=", $example);
        self::assertStringContainsString("# Custom\nCUSTOM_VALUE=kept", $example);
    }
}

final class ConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env('TEST_NAME')]
        public string $name = 'default-name',
        #[Env('TEST_ENABLED')]
        public bool $enabled = false,
        #[Env('TEST_LIMIT')]
        public int $limit = 3,
        #[Env('TEST_TOKEN', secret: true)]
        public Secret $token = new Secret(''),
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

enum ConfigHydratorFixtureMode: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}

final class EnumConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env('TEST_MODE')]
        public ConfigHydratorFixtureMode $mode = ConfigHydratorFixtureMode::Fast,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class NestedConfigHydratorFixture implements Config
{
    public bool $configured {
        get => $this->inner->configured;
    }

    public function __construct(
        public ConfigHydratorFixture $inner = new ConfigHydratorFixture(),
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return $this->inner->validate($context);
    }
}

final class ValidatingConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [
            new Issue(IssueLevel::Warning, 'fixture.warning', 'Fixture warning.'),
        ];
    }
}

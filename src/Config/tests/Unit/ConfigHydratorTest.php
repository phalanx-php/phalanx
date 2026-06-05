<?php

declare(strict_types=1);

namespace Phalanx\Config\Tests\Unit;

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
        $config = ConfigHydrator::from([
            'TEST_NAME' => 'apollo',
            'TEST_ENABLED' => 'true',
            'TEST_LIMIT' => '7',
            'TEST_TOKEN' => 'secret-token',
        ])->hydrate(ConfigHydratorFixture::class);

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
        $config = ConfigHydrator::from(['TEST_TOKEN' => null])
            ->hydrate(ConfigHydratorFixture::class);

        self::assertFalse($config->token->configured);
    }

    #[Test]
    public function invalidScalarProducesIssue(): void
    {
        try {
            ConfigHydrator::from(['TEST_LIMIT' => 'many'])
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
        $config = ConfigHydrator::from(['TEST_MODE' => 'slow'])
            ->hydrate(EnumConfigHydratorFixture::class);

        self::assertSame(ConfigHydratorFixtureMode::Slow, $config->mode);
    }

    #[Test]
    public function hydrateBuildsNestedConfigObjects(): void
    {
        $config = ConfigHydrator::from(['TEST_NAME' => 'nested'])
            ->hydrate(NestedConfigHydratorFixture::class);

        self::assertSame('nested', $config->inner->name);
    }

    #[Test]
    public function tryHydrateReturnsValidationIssuesWithoutStoringStateOnConfig(): void
    {
        $result = ConfigHydrator::from([])
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
    public function floatCoercesFromStringInput(): void
    {
        $config = ConfigHydrator::from(['TEST_RATIO' => '3.14'])
            ->hydrate(FloatConfigHydratorFixture::class);

        self::assertSame(3.14, $config->ratio);
    }

    #[Test]
    public function invalidFloatThrowsEnvFloatCode(): void
    {
        try {
            ConfigHydrator::from(['TEST_RATIO' => 'not-a-number'])
                ->hydrate(FloatConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-float', $exception->issues[0]->code);
            self::assertSame('TEST_RATIO', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Invalid float env value should produce a hydration exception.');
    }

    #[Test]
    public function invalidEnumValueThrowsEnvEnumCode(): void
    {
        try {
            ConfigHydrator::from(['TEST_MODE' => 'invalid-mode'])
                ->hydrate(EnumConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-enum', $exception->issues[0]->code);
            self::assertSame('TEST_MODE', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Invalid enum env value should produce a hydration exception.');
    }

    #[Test]
    public function nullableParameterReturnsNullWhenEnvKeyAbsent(): void
    {
        $config = ConfigHydrator::from([])
            ->hydrate(NullableOnlyConfigHydratorFixture::class);

        self::assertNull($config->nullable);
    }

    #[Test]
    public function missingNonNullableParameterWithNoDefaultThrowsEnvMissingCode(): void
    {
        try {
            ConfigHydrator::from([])
                ->hydrate(RequiredStringConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-missing', $exception->issues[0]->code);
            self::assertSame('TEST_REQUIRED', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Missing required env value should produce a hydration exception.');
    }

    #[Test]
    public function noConstructorConfigHydratesSuccessfully(): void
    {
        $config = ConfigHydrator::from([])
            ->hydrate(NoConstructorConfigHydratorFixture::class);

        self::assertTrue($config->configured);
    }

    #[Test]
    public function booleanStringsCoerceToCorrectValues(): void
    {
        foreach (['1' => true, '0' => false, 'yes' => true, 'no' => false, 'on' => true, 'off' => false] as $input => $expected) {
            $config = ConfigHydrator::from(['TEST_ENABLED' => (string) $input])
                ->hydrate(ConfigHydratorFixture::class);

            self::assertSame($expected, $config->enabled, "Expected '{$input}' to coerce to " . ($expected ? 'true' : 'false') . '.');
        }
    }

    #[Test]
    public function invalidBooleanThrowsEnvBoolCode(): void
    {
        try {
            ConfigHydrator::from(['TEST_RATIO' => '1.0', 'TEST_ENABLED_BOOL' => 'invalid'])
                ->hydrate(InvalidBoolConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-bool', $exception->issues[0]->code);
            self::assertSame('TEST_ENABLED_BOOL', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Invalid boolean env value should produce a hydration exception.');
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

    #[Test]
    public function untypedParameterWithNoDefaultThrowsUntypedCode(): void
    {
        try {
            ConfigHydrator::from([])->hydrate(UntypedParamConfigFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.untyped', $exception->issues[0]->code);
            return;
        }

        self::fail('Untyped parameter should produce a hydration exception.');
    }

    #[Test]
    public function parameterWithoutEnvAndNoDefaultThrowsMissingEnvMetadataCode(): void
    {
        try {
            ConfigHydrator::from([])->hydrate(MissingEnvMetadataConfigFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.missing-env-metadata', $exception->issues[0]->code);
            return;
        }

        self::fail('Parameter without #[Env] and no default should produce a hydration exception.');
    }

    #[Test]
    public function explicitNullForNonNullableParameterThrowsEnvNullCode(): void
    {
        try {
            ConfigHydrator::from(['TEST_REQUIRED' => null])->hydrate(RequiredStringConfigHydratorFixture::class);
        } catch (ConfigHydrationException $exception) {
            self::assertSame('config.env-null', $exception->issues[0]->code);
            self::assertSame('TEST_REQUIRED', $exception->issues[0]->envKey);
            return;
        }

        self::fail('Explicit null for non-nullable parameter should produce a hydration exception.');
    }

    #[Test]
    public function tryHydrateReturnsNullConfigWhenHydrationFails(): void
    {
        $result = ConfigHydrator::from([])
            ->tryHydrate(RequiredStringConfigHydratorFixture::class);

        self::assertNull($result->config);
        self::assertNotEmpty($result->issues);
        self::assertSame('config.env-missing', $result->issues[0]->code);
    }

    #[Test]
    public function secretRedactsInJsonAndDebugInfo(): void
    {
        $secret = new Secret('super-secret');

        self::assertSame('"[redacted]"', json_encode($secret));
        self::assertSame(['value' => '[redacted]'], $secret->__debugInfo());
    }
}

final class ConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'TEST_NAME')]
        public string $name = 'default-name',
        #[Env(key: 'TEST_ENABLED')]
        public bool $enabled = false,
        #[Env(key: 'TEST_LIMIT')]
        public int $limit = 3,
        #[Env(key: 'TEST_TOKEN', secret: true)]
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
        #[Env(key: 'TEST_MODE')]
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

final class FloatConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'TEST_RATIO')]
        public float $ratio = 1.0,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class NullableOnlyConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'TEST_NULLABLE')]
        public ?string $nullable,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class RequiredStringConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'TEST_REQUIRED')]
        public string $required,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class NoConstructorConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class InvalidBoolConfigHydratorFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'TEST_ENABLED_BOOL')]
        public bool $enabledBool = false,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class UntypedParamConfigFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public $untyped,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class MissingEnvMetadataConfigFixture implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public string $noEnvNoDefault,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

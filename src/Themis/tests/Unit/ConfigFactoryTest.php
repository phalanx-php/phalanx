<?php

declare(strict_types=1);

namespace Phalanx\Themis\Tests\Unit;

use Phalanx\Themis\Config;
use Phalanx\Themis\ConfigFactory;
use Phalanx\Themis\ConfigHydrationException;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    #[Test]
    public function fromContextHydratesCorrectly(): void
    {
        $factory = ConfigFactory::fromContext(['SPARTA_DEPTH' => '300']);
        $config = $factory->hydrate(FactorySparta300Config::class);

        self::assertSame(300, $config->depth);
        self::assertTrue($config->configured);
    }

    #[Test]
    public function hydrateThrowsOnMissingRequired(): void
    {
        $this->expectException(ConfigHydrationException::class);

        $factory = ConfigFactory::fromContext([]);
        $factory->hydrate(FactorySparta300Config::class);
    }

    #[Test]
    public function tryHydrateReturnsIssuesWithoutThrowing(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $hydrated = $factory->tryHydrate(FactorySparta300Config::class);

        self::assertNull($hydrated->config);
        self::assertNotEmpty($hydrated->issues);
        self::assertSame(IssueLevel::Error, $hydrated->issues[0]->level);
        self::assertSame('SPARTA_DEPTH', $hydrated->issues[0]->envKey);
    }

    #[Test]
    public function tryHydrateRunsValidateWhenHydrationSucceeds(): void
    {
        $factory = ConfigFactory::fromContext(['SPARTA_DEPTH' => '300']);
        $hydrated = $factory->tryHydrate(FactorySpartaWarningConfig::class);

        self::assertNotNull($hydrated->config);
        self::assertNotEmpty($hydrated->issues);
        self::assertSame(IssueLevel::Warning, $hydrated->issues[0]->level);
        self::assertSame('factory.sparta.warning', $hydrated->issues[0]->code);
    }

    #[Test]
    public function tryHydrateWithValidationContextPassesContextToValidate(): void
    {
        $factory = ConfigFactory::fromContext(['SPARTA_DEPTH' => '300']);
        $ctx = new ValidationContext(strict: true);
        $hydrated = $factory->tryHydrate(FactorySpartaWarningConfig::class, $ctx);

        self::assertNotNull($hydrated->config);
        self::assertNotEmpty($hydrated->issues);
    }

    #[Test]
    public function hydrateSucceedsWithDefaults(): void
    {
        $factory = ConfigFactory::fromContext([]);
        $config = $factory->hydrate(FactoryDefaultedConfig::class);

        self::assertSame('olympus', $config->name);
    }
}

final class FactorySparta300Config implements Config
{
    public bool $configured {
        get => $this->depth > 0;
    }

    public function __construct(
        #[Env(key: 'SPARTA_DEPTH', description: 'Depth of the phalanx')]
        public int $depth,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class FactorySpartaWarningConfig implements Config
{
    public bool $configured {
        get => $this->depth > 0;
    }

    public function __construct(
        #[Env(key: 'SPARTA_DEPTH', description: 'Depth of the phalanx')]
        public int $depth,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [
            Issue::warning(
                code: 'factory.sparta.warning',
                message: 'Spartan warning from validate().',
            ),
        ];
    }
}

final class FactoryDefaultedConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'OLYMPUS_NAME', description: 'Name of the mount')]
        public string $name = 'olympus',
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

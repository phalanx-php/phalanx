<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Config;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Task\Task;
use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigAutoRegistrationTest extends TestCase
{
    #[Test]
    public function configDeclaredInConfigsIsResolvableWithoutExplicitRegistration(): void
    {
        $result = Application::starting(['HOPLITE_RANK' => 'strategos', 'HOPLITE_SHIELD_WEIGHT' => '8'])
            ->providers(new HopliteBundle())
            ->run(Task::named(
                'test.config.auto-registration',
                static fn(ExecutionScope $scope): HopliteConfig => $scope->service(HopliteConfig::class),
            ));

        self::assertInstanceOf(HopliteConfig::class, $result);
        self::assertSame('strategos', $result->rank);
        self::assertSame(8, $result->shieldWeight);
    }

    #[Test]
    public function explicitRegistrationInServicesOverridesAutoRegistration(): void
    {
        $explicit = new HopliteConfig(rank: 'polemarch', shieldWeight: 12);

        $result = Application::starting(['HOPLITE_RANK' => 'strategos', 'HOPLITE_SHIELD_WEIGHT' => '8'])
            ->providers(new HopliteExplicitBundle($explicit))
            ->run(Task::named(
                'test.config.explicit-overrides-auto',
                static fn(ExecutionScope $scope): HopliteConfig => $scope->service(HopliteConfig::class),
            ));

        self::assertSame('polemarch', $result->rank);
        self::assertSame(12, $result->shieldWeight);
    }

    #[Test]
    public function autoRegisteredConfigReceivesContextValues(): void
    {
        $result = Application::starting(['HOPLITE_RANK' => 'lochos', 'HOPLITE_SHIELD_WEIGHT' => '7'])
            ->providers(new HopliteBundle())
            ->run(Task::named(
                'test.config.auto-values',
                static fn(ExecutionScope $scope): HopliteConfig => $scope->service(HopliteConfig::class),
            ));

        self::assertSame('lochos', $result->rank);
        self::assertSame(7, $result->shieldWeight);
    }

    #[Test]
    public function autoRegisteredConfigUsesDefaultsWhenContextKeysMissing(): void
    {
        $result = Application::starting([])
            ->providers(new HopliteBundle())
            ->run(Task::named(
                'test.config.auto-defaults',
                static fn(ExecutionScope $scope): HopliteConfig => $scope->service(HopliteConfig::class),
            ));

        self::assertSame('hoplite', $result->rank);
        self::assertSame(6, $result->shieldWeight);
    }

    #[Test]
    public function multiplebundlesWithConfigsAllResolveCorrectly(): void
    {
        $result = Application::starting([
            'HOPLITE_RANK' => 'taxiarch',
            'HOPLITE_SHIELD_WEIGHT' => '9',
            'PHALANX_DEPTH' => '16',
        ])
            ->providers(new HopliteBundle(), new PhalanxFormationBundle())
            ->run(Task::named(
                'test.config.multiple-bundles',
                static function (ExecutionScope $scope): array {
                    return [
                        'hoplite' => $scope->service(HopliteConfig::class),
                        'formation' => $scope->service(PhalanxFormationConfig::class),
                    ];
                },
            ));

        self::assertSame('taxiarch', $result['hoplite']->rank);
        self::assertSame(16, $result['formation']->depth);
    }
}

final class HopliteConfig implements Config
{
    public bool $configured {
        get => $this->rank !== '';
    }

    public function __construct(
        #[Env(key: 'HOPLITE_RANK', description: 'Rank of the hoplite')]
        private(set) string $rank = 'hoplite',
        #[Env(key: 'HOPLITE_SHIELD_WEIGHT', description: 'Shield weight in kg')]
        private(set) int $shieldWeight = 6,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class PhalanxFormationConfig implements Config
{
    public bool $configured {
        get => $this->depth > 0;
    }

    public function __construct(
        #[Env(key: 'PHALANX_DEPTH', description: 'Rank depth of the phalanx formation')]
        private(set) int $depth = 8,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class HopliteBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [HopliteConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class HopliteExplicitBundle extends ServiceBundle
{
    public function __construct(private HopliteConfig $config)
    {
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [HopliteConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;
        $services->singleton(HopliteConfig::class)
            ->factory(static fn(): HopliteConfig => $config);
    }
}

final class PhalanxFormationBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [PhalanxFormationConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

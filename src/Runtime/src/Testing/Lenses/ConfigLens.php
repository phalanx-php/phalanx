<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Config\Config;
use Phalanx\Config\ConfigCatalog;
use Phalanx\Config\ConfigFactory;
use Phalanx\Config\ConfigValidator;
use Phalanx\Config\ValidationContext;
use Phalanx\Config\ValidationResult;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;

/**
 * Test-facing Config API backed by the Application's real service graph.
 *
 * The lens drives ConfigFactory, ConfigCatalog, and ConfigValidator through a
 * managed scope so tests prove the same auto-registration and context-loading
 * path production code uses.
 */
#[Lens(
    accessor: 'config',
    returns: self::class,
    factory: ConfigLensFactory::class,
    requires: [],
)]
final class ConfigLens implements LensContract
{
    public function __construct(private readonly TestApp $app)
    {
    }

    /** @param class-string<Config> $type */
    public function hydrate(string $type): ConfigExpectation
    {
        $config = $this->app->scoped(Task::named(
            'testing.config.hydrate',
            static function (ExecutionScope $scope) use ($type): Config {
                return $scope->service(ConfigFactory::class)->hydrate($type);
            },
        ));

        return new ConfigExpectation($config);
    }

    /**
     * @param class-string<Config> ...$roots
     */
    public function validate(string ...$roots): ConfigValidationExpectation
    {
        return $this->validateWith(new ValidationContext(), ...$roots);
    }

    /**
     * @param class-string<Config> ...$roots
     */
    public function validateWith(ValidationContext $context, string ...$roots): ConfigValidationExpectation
    {
        $result = $this->app->scoped(Task::named(
            'testing.config.validate',
            static function (ExecutionScope $scope) use ($context, $roots): ValidationResult {
                $resolvedRoots = array_values($roots);

                if ($resolvedRoots === []) {
                    $resolvedRoots = $scope->service(ConfigCatalog::class)->classes();
                }

                return $scope->service(ConfigValidator::class)->validate($resolvedRoots, $context);
            },
        ));

        return new ConfigValidationExpectation($result);
    }

    public function catalog(): ConfigCatalogExpectation
    {
        $catalog = $this->app->scoped(Task::named(
            'testing.config.catalog',
            static fn(ExecutionScope $scope): ConfigCatalog => $scope->service(ConfigCatalog::class),
        ));

        return new ConfigCatalogExpectation($catalog);
    }

    public function reset(): void
    {
    }
}

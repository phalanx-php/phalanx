<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Service;

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FactoryDependencyResolutionTest extends TestCase
{
    #[Test]
    public function factoryParametersResolveServicesConfigsAndCurrentScope(): void
    {
        $result = Application::starting(['FACTORY_VALUE' => 'athena'])
            ->providers(new AutoResolvedFactoryBundle())
            ->run(Task::named(
                'test.service.factory-dependency-resolution',
                static function (ExecutionScope $scope): array {
                    $service = $scope->service(AutoResolvedConsumer::class);

                    return [
                        'scope' => $scope === $service->scope,
                        'value' => $service->dependency->value,
                    ];
                },
            ));

        self::assertSame([
            'scope' => true,
            'value' => 'athena',
        ], $result);
    }

    #[Test]
    public function factoryParametersMustDeclareObjectTypes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'Service %s factory parameter $value must declare a single object type.',
            UntypedFactoryService::class,
        ));

        Application::starting()
            ->providers(new UntypedFactoryBundle())
            ->run(Task::named(
                'test.service.factory-dependency-resolution.untyped',
                static fn(ExecutionScope $scope): object => $scope->service(UntypedFactoryService::class),
            ));
    }
}

final class AutoResolvedFactoryBundle extends ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->config(
            AutoResolvedConfig::class,
            static fn(array $ctx): AutoResolvedConfig => new AutoResolvedConfig((string) $ctx['FACTORY_VALUE']),
        );

        $services->singleton(AutoResolvedDependency::class)
            ->factory(static fn(AutoResolvedConfig $config): AutoResolvedDependency => new AutoResolvedDependency(
                $config->value,
            ));

        $services->scoped(AutoResolvedConsumer::class)
            ->factory(static fn(
                AutoResolvedDependency $dependency,
                Scope $scope,
            ): AutoResolvedConsumer => new AutoResolvedConsumer(
                $dependency,
                $scope,
            ));
    }
}

final readonly class AutoResolvedConfig
{
    public function __construct(
        public string $value,
    ) {
    }
}

final readonly class AutoResolvedDependency
{
    public function __construct(
        public string $value,
    ) {
    }
}

final readonly class AutoResolvedConsumer
{
    public function __construct(
        public AutoResolvedDependency $dependency,
        public Scope $scope,
    ) {
    }
}

final class UntypedFactoryBundle extends ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(UntypedFactoryService::class)
            ->factory(static fn($value): UntypedFactoryService => new UntypedFactoryService());
    }
}

final class UntypedFactoryService
{
}

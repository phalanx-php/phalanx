<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Application;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Iris\Iris;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IrisFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new HttpClientConfig(connectTimeout: 1.25, userAgent: 'IrisFacadeTest');

        $result = Application::starting()
            ->providers(Iris::services($config))
            ->run(Task::named(
                'test.iris.facade.services',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(HttpClientConfig::class);
                    $client = Iris::client($scope);

                    self::assertInstanceOf(HttpClientConfig::class, $resolvedConfig);
                    self::assertInstanceOf(HttpClient::class, $client);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                        'userAgent' => $resolvedConfig->userAgent,
                    ];
                },
            ));

        self::assertSame([
            'connectTimeout' => 1.25,
            'userAgent' => 'IrisFacadeTest',
        ], $result);
    }

    #[Test]
    public function duplicateBundleRegistrationIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        Application::starting()
            ->providers(Iris::services(), Iris::services())
            ->run(Task::named(
                'test.iris.facade.duplicate-error',
                static fn(ExecutionScope $scope): null => null,
            ));
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Application;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Iris\Iris;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IrisFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new HttpClientConfig(connectTimeout: 1.25, userAgent: 'IrisFacadeTest');
        $bundle = Iris::services($config);

        $result = [];

        TestScope::compile(services: static fn($services, $context): mixed => $bundle->services($services, $context))
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(HttpClientConfig::class);
                $client = Iris::client($scope);

                self::assertInstanceOf(HttpClientConfig::class, $resolvedConfig);
                self::assertInstanceOf(HttpClient::class, $client);

                $result = [
                    'connectTimeout' => $resolvedConfig->connectTimeout,
                    'userAgent' => $resolvedConfig->userAgent,
                ];
            });

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
            ->compile();
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\Application;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpClientConfig;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpClientFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new HttpClientConfig(connectTimeout: 1.25, userAgent: 'HttpClientFacadeTest');
        $bundle = HttpClient::services($config);

        $result = [];

        TestScope::compile(services: static fn($services, $context): mixed => $bundle->services($services, $context))
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(HttpClientConfig::class);
                $client = HttpClient::client($scope);

                self::assertInstanceOf(HttpClientConfig::class, $resolvedConfig);
                self::assertInstanceOf(HttpClient::class, $client);

                $result = [
                    'connectTimeout' => $resolvedConfig->connectTimeout,
                    'userAgent' => $resolvedConfig->userAgent,
                ];
            });

        self::assertSame([
            'connectTimeout' => 1.25,
            'userAgent' => 'HttpClientFacadeTest',
        ], $result);
    }

    #[Test]
    public function duplicateBundleRegistrationIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        Application::starting()
            ->providers(HttpClient::services(), HttpClient::services())
            ->compile();
    }
}

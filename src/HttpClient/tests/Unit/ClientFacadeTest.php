<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\Application;
use Phalanx\HttpClient\Client;
use Phalanx\HttpClient\Config;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpClientFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new \Phalanx\HttpClient\Config(connectTimeout: 1.25, userAgent: 'HttpClientFacadeTest');
        $bundle = \Phalanx\HttpClient\Client::services($config);

        $result = [];

        TestScope::compile(services: static function ($services, $context) use ($bundle): void { $bundle->services($services, $context); })
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(\Phalanx\HttpClient\Config::class);
                $client = \Phalanx\HttpClient\Client::client($scope);

                self::assertInstanceOf(\Phalanx\HttpClient\Config::class, $resolvedConfig);
                self::assertInstanceOf(\Phalanx\HttpClient\Client::class, $client);

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
            ->providers(\Phalanx\HttpClient\Client::services(), \Phalanx\HttpClient\Client::services())
            ->compile();
    }
}

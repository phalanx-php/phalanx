<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class HttpClientFacadeTest extends PhalanxTestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new \Phalanx\HttpClient\Config(connectTimeout: 1.25, userAgent: 'HttpClientFacadeTest');
        $bundle = \Phalanx\HttpClient\Client::services($config);

        $result = $this->testApp(bundles: $bundle)->application->scoped(
            Task::named(
                'test.httpclient.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(\Phalanx\HttpClient\Config::class);
                    $client = \Phalanx\HttpClient\Client::client($scope);

                    self::assertInstanceOf(\Phalanx\HttpClient\Config::class, $resolvedConfig);
                    self::assertInstanceOf(\Phalanx\HttpClient\Client::class, $client);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                        'userAgent' => $resolvedConfig->userAgent,
                    ];
                },
            ),
        );

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

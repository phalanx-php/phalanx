<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\HttpClient\Client as HttpClient;
use Phalanx\HttpClient\Config;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ClientTest extends PhalanxTestCase
{
    #[Test]
    public function servicesRegistersConfiguredHttpClient(): void
    {
        $config = new Config(connectTimeout: 1.25, userAgent: 'ClientTest');
        $bundle = HttpClient::services($config);

        $result = $this->testApp(bundles: $bundle)->scoped(
            Task::named(
                'test.httpclient.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(Config::class);
                    $client = HttpClient::client($scope);

                    self::assertInstanceOf(Config::class, $resolvedConfig);
                    self::assertInstanceOf(HttpClient::class, $client);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                        'userAgent' => $resolvedConfig->userAgent,
                    ];
                },
            ),
        );

        self::assertSame([
            'connectTimeout' => 1.25,
            'userAgent' => 'ClientTest',
        ], $result);
    }

    #[Test]
    public function duplicateBundleRegistrationIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        $this->testApp([], HttpClient::services(), HttpClient::services());
    }
}

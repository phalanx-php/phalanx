<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use Phalanx\Application;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\WsGateway;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HermesFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new WsClientConfig(connectTimeout: 1.5);

        $result = Application::starting()
            ->providers(Hermes::services($clientConfig))
            ->run(Task::named(
                'test.hermes.facade.services',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(WsClientConfig::class);
                    $client = Hermes::client($scope);

                    self::assertInstanceOf(WsGateway::class, Hermes::gateway($scope));
                    self::assertInstanceOf(WsClient::class, $client);
                    self::assertInstanceOf(WsClientConfig::class, $resolvedConfig);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                    ];
                },
            ));

        self::assertSame([
            'connectTimeout' => 1.5,
        ], $result);
    }
}

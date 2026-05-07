<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use GuzzleHttp\Psr7\Request;
use Phalanx\Application;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsHandshake;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HermesFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayHandshakeAndClientConfig(): void
    {
        $clientConfig = new WsClientConfig(connectTimeout: 1.5);

        $result = Application::starting()
            ->providers(Hermes::services(['chat'], $clientConfig))
            ->run(Task::named(
                'test.hermes.facade.services',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(WsClientConfig::class);
                    $handshake = $scope->service(WsHandshake::class);

                    self::assertInstanceOf(WsGateway::class, Hermes::gateway($scope));
                    self::assertInstanceOf(WsHandshake::class, $handshake);
                    self::assertInstanceOf(WsClientConfig::class, $resolvedConfig);

                    $response = $handshake->negotiate(new Request('GET', '/ws', [
                        'Host' => 'localhost',
                        'Upgrade' => 'websocket',
                        'Connection' => 'Upgrade',
                        'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                        'Sec-WebSocket-Version' => '13',
                        'Sec-WebSocket-Protocol' => 'chat',
                    ]));

                    self::assertTrue($handshake->isSuccessful($response));

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                        'protocol' => $response->getHeaderLine('Sec-WebSocket-Protocol'),
                    ];
                },
            ));

        self::assertSame([
            'connectTimeout' => 1.5,
            'protocol' => 'chat',
        ], $result);
    }
}

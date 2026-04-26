<?php

declare(strict_types=1);

namespace Acme\Fleet;

use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Redis\RedisPubSub;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsScope;

final class CustomerChatHandler implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
        $conn = $scope->connection;
        $tenantId = $scope->params->get('tenantId');
        $sessionId = $scope->params->get('sessionId');
        $gateway = $scope->service(WsGateway::class);

        $gateway->register($conn);

        $scope->concurrent([
            Task::of(static function ($s) use ($sessionId, $conn): void {
                $s->service(RedisPubSub::class)->subscribe(
                    "session:{$sessionId}:response",
                    static function (string $message) use ($conn): void {
                        if ($conn->isOpen) {
                            $conn->send(WsMessage::text($message));
                        }
                    }
                );
            }),

            Task::of(static function ($s) use ($conn, $tenantId, $sessionId): void {
                foreach ($conn->inbound->consume() as $msg) {
                    if (!$msg->isText) {
                        continue;
                    }

                    $input = $msg->decode();

                    $s->service(RedisPubSub::class)->publish(
                        'agent:tasks',
                        json_encode([
                            'tenant_id' => $tenantId,
                            'session_id' => $sessionId,
                            'message' => $input['text'],
                            'type' => 'customer_message',
                        ])
                    );
                }
            }),
        ]);

        $gateway->unregister($conn);
    }
}

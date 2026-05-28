<?php

declare(strict_types=1);

namespace ThreePath;

use Phalanx\Suspendable;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket as DatagramSocket;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class StbTransport
{
    public function __construct(
        private readonly DatagramFactory $factory,
        private readonly Suspendable $scope,
        private readonly StbConfig $config,
    ) {}

    public function send(string $ip, string $deviceId, StbCommand $command): StbResponse
    {
        $payload = self::buildPayload($deviceId, $command, $this->config->apiKey);
        $address = "{$ip}:{$this->config->port}";

        $response = $this->scope->await(
            \React\Promise\Timer\timeout(
                $this->sendAndReceive($address, $payload),
                $this->config->timeoutSeconds,
            )->catch(static fn() => null),
        );

        if ($response === null) {
            return StbResponse::timeout($ip, $command->name);
        }

        return StbResponse::fromRaw($ip, $command->name, $response);
    }

    public function discover(string $ip): StbResponse
    {
        return $this->send($ip, '0', StbCommand::helloDiscovery());
    }

    private function sendAndReceive(string $address, string $payload): PromiseInterface
    {
        $deferred = new Deferred();

        $this->factory->createClient($address)->then(
            static function (DatagramSocket $socket) use ($deferred, $payload): void {
                $buffer = '';

                $socket->on('message', static function (string $data) use ($deferred, $socket, &$buffer): void {
                    $terminal = false;

                    if ($data === 'END') {
                        $terminal = true;
                        $data = '';
                    } elseif (str_ends_with($data, 'END')) {
                        $terminal = true;
                        $data = substr($data, 0, -3);
                    }

                    if ($data !== '') {
                        $colonPos = strpos($data, ':');
                        if ($colonPos !== false && preg_match('#^\d+/\d+:#', $data)) {
                            $content = substr($data, $colonPos + 1);
                            // Strip the ack line ("Command received: ...") if present, keep any data after it
                            if (str_starts_with($content, 'Command received:')) {
                                $newline = strpos($content, "\n");
                                $content = $newline !== false ? substr($content, $newline + 1) : '';
                            }
                            $buffer .= $content;
                        } else {
                            $buffer .= $data;
                        }
                    }

                    if ($terminal) {
                        $socket->close();
                        $deferred->resolve($buffer);
                    }
                });

                $socket->on('error', static function (\Throwable $e) use ($deferred, $socket): void {
                    $socket->close();
                    $deferred->reject($e);
                });

                $socket->send($payload);
            },
            static function (\Throwable $e) use ($deferred): void {
                $deferred->reject($e);
            },
        );

        return $deferred->promise();
    }

    private static function buildPayload(string $deviceId, StbCommand $command, string $apiKey): string
    {
        $message = [
            'id' => random_int(10000000, 99999999),
            'command' => $command->name,
            'api_key' => $apiKey,
            'description' => $command->description,
        ];

        if ($command->payload !== []) {
            $message['payload'] = $command->payload;
        }

        return "{$deviceId}:msg:" . json_encode($message, JSON_THROW_ON_ERROR);
    }
}

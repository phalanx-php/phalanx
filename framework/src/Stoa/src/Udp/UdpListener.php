<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Udp;

use OpenSwoole\Server;
use Phalanx\AppHost;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;

/**
 * Boot a UDP listener bound to a host:port that hands every packet to
 * the registered handler.
 *
 * Each packet opens a {@see UdpSession} managed resource so the
 * supervisor sees in-flight peers and the diagnostics surface can
 * answer "live UDP sessions" queries. Sessions are short-lived: handler
 * returns, session closes, resource transitions to terminal.
 *
 * Closure policy: the OpenSwoole Server::on('Packet', ...) callback is
 * a first-class-callable bound to this listener instance so the call
 * site stays out of test/runner static-closure footguns.
 */
final class UdpListener
{
    private ?Server $server = null;
    private ?ManagedResourceHandle $listenerHandle = null;

    public function __construct(
        private readonly AppHost $app,
        private readonly UdpListenerConfig $config,
        private readonly UdpPacketHandler $handler,
    ) {
    }

    public function start(): void
    {
        if ($this->server !== null) {
            return;
        }

        $server = new Server(
            $this->config->host,
            $this->config->port,
            SWOOLE_PROCESS,
            SWOOLE_SOCK_UDP,
        );
        $server->set([
            'worker_num' => $this->config->workerNum,
            'enable_coroutine' => true,
            'package_max_length' => $this->config->maxPacketSize,
            'enable_broadcast' => $this->config->broadcast,
        ]);

        $server->on('WorkerStart', $this->onWorkerStart(...));
        $server->on('Packet', $this->onPacket(...));

        $this->server = $server;
        $server->start();
    }

    public function stop(): void
    {
        $this->server?->shutdown();
        $this->server = null;
    }

    /**
     * @param array{address: string, port: int, server_socket?: int, server_port?: int, dispatch_time?: float} $clientInfo
     */
    public function dispatch(string $payload, array $clientInfo): void
    {
        $packet = new UdpPacket(
            payload: $payload,
            remoteAddress: $clientInfo['address'],
            remotePort: $clientInfo['port'],
            clientInfo: $clientInfo,
        );

        if ($this->server === null) {
            return;
        }

        $session = new UdpSession(
            packet: $packet,
            runtime: $this->app->runtime(),
            server: $this->server,
            serverSocket: $clientInfo['server_socket'] ?? -1,
            parentHandle: $this->listenerHandle,
        );

        try {
            ($this->handler)($session);
        } finally {
            if (!$session->isClosed()) {
                $session->close();
            }
        }
    }

    private function onWorkerStart(Server $server, int $workerId): void
    {
        $this->app->startup();
        $runtime = $this->app->runtime();
        $this->listenerHandle = $runtime->memory->resources->open(
            type: StoaResourceSid::UdpListener,
            id: $runtime->memory->ids->nextRuntime('udp-listener'),
        );
        $this->listenerHandle = $runtime->memory->resources->activate($this->listenerHandle);
    }

    /**
     * @param array{address: string, port: int, server_socket?: int, server_port?: int, dispatch_time?: float} $clientInfo
     */
    private function onPacket(Server $server, string $payload, array $clientInfo): void
    {
        $this->dispatch($payload, $clientInfo);
    }
}

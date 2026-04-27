<?php

declare(strict_types=1);

namespace Phalanx\Skopos\LiveReload;

use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

use Psr\Http\Message\ServerRequestInterface;

final class Server
{
    /** @var \SplObjectStorage<ThroughStream, true> */
    private \SplObjectStorage $clients;
    private ?HttpServer $httpServer = null;
    private ?SocketServer $socket = null;
    private int $port;

    private function __construct(int $port)
    {
        $this->port = $port;
        $this->clients = new \SplObjectStorage();
    }

    public static function on(int $port = 35729): self
    {
        return new self($port);
    }

    public function start(): void
    {
        $clients = $this->clients;
        $port = $this->port;

        $this->httpServer = new HttpServer(static function (ServerRequestInterface $request) use ($clients, $port): Response {
            $path = $request->getUri()->getPath();

            if ($path === '/livereload.js') {
                return new Response(
                    200,
                    [
                        'Content-Type' => 'application/javascript',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                    ClientScript::js($port),
                );
            }

            if ($path === '/sse') {
                $stream = new ThroughStream();
                $clients->attach($stream, true);

                $stream->on('close', static function () use ($clients, $stream): void {
                    $clients->detach($stream);
                });

                return new Response(
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'Cache-Control' => 'no-cache',
                        'Connection' => 'keep-alive',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                    $stream,
                );
            }

            return new Response(404, [], 'Not Found');
        });

        $this->socket = new SocketServer("0.0.0.0:{$this->port}");
        $this->httpServer->listen($this->socket);
    }

    public function reload(): void
    {
        foreach ($this->clients as $stream) {
            $stream->write("data: reload\n\n");
        }
    }

    public function stop(): void
    {
        foreach ($this->clients as $stream) {
            $stream->end();
        }

        $this->clients = new \SplObjectStorage();

        $this->socket?->close();
        $this->socket = null;
        $this->httpServer = null;
    }
}

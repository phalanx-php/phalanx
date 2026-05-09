<?php

declare(strict_types=1);

namespace Phalanx\Skopos\LiveReload;

use OpenSwoole\Coroutine\Http\Server as CoroutineHttpServer;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Throwable;

/**
 * In-process LiveReload HTTP server using OpenSwoole's coroutine HTTP
 * server primitive. Lives inside the Skopos main coroutine context as a
 * supervised task — the server's start() blocks the calling coroutine,
 * so callers should run it via $scope->go() / $scope->defer().
 *
 * Two endpoints:
 *
 *   GET /livereload.js  — serves the EventSource client (CORS *)
 *   GET /sse            — opens an SSE channel; handler holds the response
 *                         open and registers it with BroadcasterChannel
 *
 * Cancellation: stop() drains BroadcasterChannel, calls shutdown() on the
 * underlying server. The SSE handler coroutines unblock when their parent
 * scope is cancelled; the heartbeat loop is sized so disconnects are
 * detected within ~5s.
 */
final class Server
{
    public bool $isStopping {
        get => $this->stopping;
    }

    private ?CoroutineHttpServer $server = null;

    private bool $stopping = false;

    public function __construct(
        private(set) int $port,
        private readonly BroadcasterChannel $broadcaster,
        private readonly string $host = '0.0.0.0',
    ) {
    }

    public static function on(int $port, BroadcasterChannel $broadcaster): self
    {
        return new self($port, $broadcaster);
    }

    /**
     * Blocks the calling coroutine until stop() is invoked. Run inside
     * $scope->go() so the server runs alongside other supervised tasks.
     */
    public function start(ExecutionScope $scope): void
    {
        $server = new CoroutineHttpServer($this->host, $this->port);
        $this->server = $server;
        $this->stopping = false;

        $broadcaster = $this->broadcaster;
        $port = $this->port;

        $server->handle(
            '/livereload.js',
            static function (Request $request, Response $response) use ($port): void {
                $method = $request->server['request_method'] ?? 'GET';

                $response->status(200);
                $response->header('Content-Type', 'application/javascript');
                $response->header('Access-Control-Allow-Origin', '*');
                $response->end($method === 'HEAD' ? '' : ClientScript::js($port));
            },
        );

        $server->handle(
            '/sse',
            static function (Request $request, Response $response) use ($broadcaster, $scope): void {
                $method = $request->server['request_method'] ?? 'GET';
                if ($method !== 'GET') {
                    $response->status(405);
                    $response->end('Method Not Allowed');
                    return;
                }

                $response->status(200);
                $response->header('Content-Type', 'text/event-stream');
                $response->header('Cache-Control', 'no-cache');
                $response->header('Connection', 'keep-alive');
                $response->header('X-Accel-Buffering', 'no');
                $response->header('Access-Control-Allow-Origin', '*');

                if ($response->write(": connected\n\n") === false) {
                    return;
                }

                $id = $broadcaster->subscribe($response);

                try {
                    while (!$scope->isCancelled && $response->isWritable()) {
                        $scope->delay(5.0);
                        if ($response->write(": heartbeat\n\n") === false) {
                            break;
                        }
                    }
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable) {
                } finally {
                    $broadcaster->unsubscribe($id);

                    try {
                        if ($response->isWritable()) {
                            $response->end();
                        }
                    } catch (Cancelled $e) {
                        throw $e;
                    } catch (Throwable) {
                    }
                }
            },
        );

        $server->handle('/', static function (Request $request, Response $response): void {
            $path = $request->server['request_uri'] ?? '/';

            $response->status(404);
            $response->end("Not Found: {$path}");
        });

        $server->start();
    }

    public function stop(): void
    {
        $this->stopping = true;
        $this->broadcaster->closeAll();
        $this->server?->shutdown();
        $this->server = null;
    }
}

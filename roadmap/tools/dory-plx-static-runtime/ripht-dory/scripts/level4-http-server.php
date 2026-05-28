<?php

declare(strict_types=1);

// Level 4: Does an Swoole HTTP server work under the embed SAPI?
//
// This is the critical test. SIMPLE_MODE avoids fork() entirely --
// single process, single thread, coroutine-based request handling.
//
// Run with: dory-poc scripts/level4-http-server.php
// Test with: curl http://127.0.0.1:9501/
// Shutdown: Ctrl+C (SIGINT) or kill -TERM <pid>
//
// Expected:
//   listening on 127.0.0.1:9501
//   curl returns "phalanx" with 200
//   Ctrl+C triggers graceful shutdown
//
// After this works, test with --hooks flag:
//   dory-poc scripts/level4-http-server.php --hooks
//   This routes PHP output through ripht's ExecutionHooks

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$host = '127.0.0.1';
$port = 9501;

// SWOOLE_BASE: no fork, single-process reactor mode
$server = new Server($host, $port, SWOOLE_BASE);

$server->set([
    'worker_num' => 1,
    'log_level' => SWOOLE_LOG_WARNING,
]);

$requestCount = 0;

$server->on('start', static function (Server $server) use ($host, $port): void {
    echo "listening on {$host}:{$port}\n";
    echo "pid: " . getmypid() . "\n";
    echo "test: curl http://{$host}:{$port}/\n";
    echo "stop: curl http://{$host}:{$port}/shutdown or Ctrl+C\n";
});

$server->on('request', static function (Request $req, Response $res) use ($server, &$requestCount): void {
    $requestCount++;
    $path = $req->server['request_uri'] ?? '/';

    if ($path === '/shutdown') {
        $res->header('Content-Type', 'text/plain');
        $res->end("shutting down after {$requestCount} requests\n");
        $server->shutdown();
        return;
    }

    if ($path === '/health') {
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode([
            'status' => 'ok',
            'sapi' => php_sapi_name(),
            'requests' => $requestCount,
            'memory' => memory_get_usage(true),
            'pid' => getmypid(),
        ], JSON_THROW_ON_ERROR) . "\n");
        return;
    }

    $res->header('Content-Type', 'text/plain');
    $res->end("phalanx\n");
});

$server->on('shutdown', static function () use (&$requestCount): void {
    echo "server stopped after {$requestCount} requests\n";
});

$server->start();

echo "\nlevel 4: server exited cleanly\n";

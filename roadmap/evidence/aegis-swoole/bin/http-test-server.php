<?php

declare(strict_types=1);

$autoload = $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
require $autoload;

$server = new OpenSwoole\Http\Server('127.0.0.1', 0);

$server->set([
    'worker_num' => 1,
    'log_level' => 4,
    'log_file' => '/dev/null',
    'enable_coroutine' => true,
]);

$server->on('start', static function (OpenSwoole\Http\Server $s): void {
    $port = $s->port;
    fwrite(STDOUT, "PORT={$port}\n");
    fflush(STDOUT);
});

/** @var array<string, int> $flakyHits */
$flakyHits = [];

$server->on('request', static function (
    OpenSwoole\Http\Request $req,
    OpenSwoole\Http\Response $resp,
) use (&$flakyHits): void {
    $path = (string) ($req->server['request_uri'] ?? '/');

    if ($path === '/ok') {
        $resp->status(200);
        $resp->end('ok');
        return;
    }

    if ($path === '/slow') {
        $ms = (int) ($req->get['ms'] ?? 100);
        OpenSwoole\Coroutine::usleep($ms * 1000);
        $resp->status(200);
        $resp->end('slow');
        return;
    }

    if ($path === '/flaky') {
        $id = (string) ($req->get['id'] ?? 'default');
        $after = (int) ($req->get['after'] ?? 2);
        $hits = ($flakyHits[$id] ?? 0) + 1;
        $flakyHits[$id] = $hits;
        if ($hits <= $after) {
            $resp->status(503);
            $resp->end("flaky-fail-{$hits}");
            return;
        }
        $resp->status(200);
        $resp->end("flaky-ok-{$hits}");
        return;
    }

    if ($path === '/echo') {
        $resp->status(200);
        $resp->end($req->getContent());
        return;
    }

    $resp->status(404);
    $resp->end('not found');
});

$server->start();

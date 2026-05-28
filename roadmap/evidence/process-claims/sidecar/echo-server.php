<?php

declare(strict_types=1);

$socketPath = $argv[1] ?? null;
$workMs = (int) ($argv[2] ?? 100);
if ($socketPath === null) {
    fwrite(STDERR, "usage: echo-server.php <socket-path> [work-ms]\n");
    exit(2);
}

if (file_exists($socketPath)) {
    unlink($socketPath);
}

$server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(3);
}

fwrite(STDERR, "sidecar listening on {$socketPath}, simulated work={$workMs}ms\n");

stream_set_blocking($server, true);

while (true) {
    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        continue;
    }
    $line = fgets($client);
    if ($line === false) {
        fclose($client);
        continue;
    }
    usleep($workMs * 1000);
    fwrite($client, "echo:" . trim($line) . "\n");
    fclose($client);
}

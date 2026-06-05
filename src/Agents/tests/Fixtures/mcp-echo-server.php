#!/usr/bin/env php
<?php

declare(strict_types=1);

function respond(int|string $id, mixed $result): void
{
    $payload = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $payload . "\n");
    fflush(STDOUT);
}

$stdin = fopen('php://stdin', 'rb');

if ($stdin === false) {
    fwrite(STDERR, "Failed to open stdin\n");
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $msg = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $method = $msg['method'] ?? '';
    $params = $msg['params'] ?? [];
    $id = $msg['id'] ?? null;

    switch ($method) {
        case 'initialize':
            respond($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => ['tools' => []],
                'serverInfo'      => ['name' => 'echo-server', 'version' => '1.0'],
            ]);
            break;

        case 'notifications/initialized':
            // Notification: no id, no response.
            break;

        case 'tools/list':
            respond($id, [
                'tools' => [
                    [
                        'name'        => 'echo_tool',
                        'description' => 'Echoes input',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => ['message' => ['type' => 'string']],
                            'required'   => ['message'],
                        ],
                    ],
                ],
            ]);
            break;

        case 'tools/call':
            $message = $params['arguments']['message'] ?? '';
            respond($id, [
                'content' => [['type' => 'text', 'text' => 'Echo: ' . $message]],
                'isError'  => false,
            ]);
            break;

        case 'shutdown':
            respond($id, null);
            fclose($stdin);
            exit(0);

        default:
            // Unknown method: ignore.
            break;
    }
}

fclose($stdin);
exit(0);

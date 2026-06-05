<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp\Client;

use Phalanx\Agents\Mcp\McpClient;
use Phalanx\Agents\Mcp\McpConnection;
use Phalanx\Agents\Mcp\McpServer;
use Phalanx\Agents\Mcp\Transport;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Scope\TaskScope;

final readonly class SseClient implements McpClient
{
    public function __construct(
        private \Phalanx\HttpClient\Client $httpClient,
    ) {
    }

    public function connect(TaskScope $scope, McpServer $server): McpConnection
    {
        if ($server->transport !== Transport::Sse) {
            throw new \RuntimeException(
                "SseClient requires Transport::Sse, got {$server->transport->value}",
            );
        }

        $sseStream = $this->httpClient->stream(
            $scope,
            \Phalanx\HttpClient\Request::get($server->endpoint, ['Accept' => ['text/event-stream']]),
        );

        if ($sseStream->status !== 200) {
            $sseStream->abort('non-200-status');

            throw new \RuntimeException(
                "SSE connection to {$server->endpoint} failed with HTTP {$sseStream->status}",
            );
        }

        $parser = new SseParser();
        $lines = self::streamLines($scope, $sseStream);
        $events = $parser->parse($lines);

        $postUrl = self::extractEndpointUrl($events, $server->endpoint);

        $connection = new SseConnection(
            httpClient: $this->httpClient,
            sseStream: $sseStream,
            events: $events,
            postUrl: $postUrl,
            serverName: $server->name,
        );

        $scope->onDispose(static function () use ($sseStream): void {
            $sseStream->close();
        });

        return $connection;
    }

    /**
     * Advances the event generator until an `endpoint` event is found, then
     * returns the resolved POST URL from its data field.
     *
     * @param \Generator<int, array{event: string, data: string, id: ?string}> $events
     */
    private static function extractEndpointUrl(\Generator $events, string $serverEndpoint): string
    {
        while ($events->valid()) {
            $event = $events->current();
            $events->next();

            if ($event['event'] !== 'endpoint') {
                continue;
            }

            $data = $event['data'];

            if (
                $data !== '' && (
                str_starts_with($data, 'http://') ||
                str_starts_with($data, 'https://') ||
                str_starts_with($data, '/')
                )
            ) {
                return self::resolvePostUrl($serverEndpoint, $data);
            }
        }

        throw new \RuntimeException("SSE server at {$serverEndpoint} did not send an endpoint event");
    }

    /**
     * Yields lines from the SSE stream, preserving empty strings so the
     * SSE parser can detect event boundaries. Stream::lines() strips
     * empty lines, so we drive read() directly.
     *
     * @return \Generator<int, string>
     */
    private static function streamLines(Scope&Suspendable $scope, \Phalanx\HttpClient\Stream $sseStream): \Generator
    {
        $buffer = '';

        while (!$sseStream->eof) {
            $chunk = $sseStream->read($scope);

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nl);
                $buffer = (string) substr($buffer, $nl + 1);
                yield rtrim($line, "\r");
            }
        }

        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
    }

    /**
     * Resolves the POST URL relative to the server endpoint when the server
     * sends a path-only value such as `/mcp/post`.
     */
    private static function resolvePostUrl(string $serverEndpoint, string $postData): string
    {
        if (str_starts_with($postData, 'http://') || str_starts_with($postData, 'https://')) {
            return $postData;
        }

        $parsed = parse_url($serverEndpoint);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port . $postData;
    }
}

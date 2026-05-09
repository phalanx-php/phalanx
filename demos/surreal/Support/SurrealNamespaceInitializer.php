<?php

declare(strict_types=1);

namespace Phalanx\Demos\Surreal\Support;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;

/**
 * Bootstraps the SurrealDB namespace and database over the RPC endpoint.
 *
 * Sends a DEFINE NAMESPACE / DEFINE DATABASE statement via the HTTP RPC
 * interface using root credentials. Returns false and prints a diagnostic
 * when the bootstrap request fails; re-throws Cancelled.
 */
final class SurrealNamespaceInitializer
{
    public function __invoke(ExecutionScope $scope, string $endpoint): bool
    {
        try {
            $http = $scope->service(HttpClient::class);
            $body = json_encode([
                'id' => 1,
                'method' => 'query',
                'params' => ['DEFINE NAMESPACE olympus; USE NAMESPACE olympus; DEFINE DATABASE pantheon;'],
            ], JSON_THROW_ON_ERROR);

            $response = $http->request($scope, new HttpRequest(
                method: 'POST',
                url: $endpoint . '/rpc',
                headers: [
                    'accept' => ['application/json'],
                    'authorization' => ['Basic ' . base64_encode('root:root')],
                    'content-type' => ['application/json'],
                ],
                body: $body,
            ));

            if (!$response->successful) {
                printf("  FAIL namespace/database bootstrap returned HTTP %d\n", $response->status);
                return false;
            }

            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            $error = is_array($payload) ? $payload['error'] ?? null : null;

            if ($error !== null) {
                printf("  FAIL namespace/database bootstrap returned %s\n", is_scalar($error) ? (string) $error : 'error');
                return false;
            }

            return true;
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            printf("  FAIL namespace/database bootstrap failed: %s\n", $e->getMessage());
            return false;
        }
    }
}

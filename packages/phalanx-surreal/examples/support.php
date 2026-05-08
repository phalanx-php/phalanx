<?php

declare(strict_types=1);

use Phalanx\Cancellation\Cancelled;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\System\StreamingProcessHandle;

function phalanxSurrealExampleBinary(): ?string
{
    $path = getenv('PATH');
    if (!is_string($path) || $path === '') {
        return null;
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'surreal';

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function phalanxSurrealExamplePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    if ($server === false) {
        return random_int(20_000, 40_000);
    }

    $name = stream_socket_get_name($server, false);
    fclose($server);

    if (!is_string($name)) {
        return random_int(20_000, 40_000);
    }

    $port = parse_url('tcp://' . $name, PHP_URL_PORT);
    return is_int($port) ? $port : random_int(20_000, 40_000);
}

function phalanxSurrealExampleWaitForServer(
    ExecutionScope $scope,
    Surreal $surreal,
    StreamingProcessHandle $server,
): bool {
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (!$server->isRunning()) {
            return false;
        }

        try {
            if (in_array($surreal->health(), [200, 204], true)) {
                return true;
            }
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable) {
        }

        $scope->delay(0.05);
    }

    return false;
}

function phalanxSurrealExamplePrintServerError(StreamingProcessHandle $server): void
{
    $error = trim($server->getIncrementalErrorOutput());
    if ($error !== '') {
        printf("\nServer error: %s\n", $error);
    }
}

function phalanxSurrealExampleInitialize(
    ExecutionScope $scope,
    string $endpoint,
): bool {
    try {
        $http = $scope->service(HttpClient::class);
        $body = json_encode([
            'id' => 1,
            'method' => 'query',
            'params' => ['DEFINE NAMESPACE athena; USE NAMESPACE athena; DEFINE DATABASE wisdom;'],
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

function phalanxSurrealExampleHasRecord(mixed $value, string $name): bool
{
    if (!is_array($value)) {
        return false;
    }

    if (($value['name'] ?? null) === $name) {
        return true;
    }

    foreach ($value as $item) {
        if (phalanxSurrealExampleHasRecord($item, $name)) {
            return true;
        }
    }

    return false;
}

function phalanxSurrealExampleHasValue(mixed $value, mixed $expected): bool
{
    if ($value === $expected) {
        return true;
    }

    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $item) {
        if (phalanxSurrealExampleHasValue($item, $expected)) {
            return true;
        }
    }

    return false;
}

function phalanxSurrealExampleLiveNotification(
    ?SurrealLiveNotification $notification,
    SurrealLiveAction $action,
    string $signal,
): bool {
    return $notification instanceof SurrealLiveNotification
        && $notification->action === $action
        && phalanxSurrealExampleHasValue($notification->result, $signal);
}

function phalanxSurrealExampleCannotRun(string $title, string $reason, string $fix): never
{
    printf("%s\n", $title);
    printf("%s\n", str_repeat('=', strlen($title)));
    echo "Status: cannot run\n\n";
    printf("Missing requirement: %s\n\n", $reason);
    printf("Fix: %s\n", $fix);
    exit(0);
}

<?php

declare(strict_types=1);

/**
 * @param list<string> $argv
 * @return array<string, mixed>
 */
function phalanxAthenaExampleContext(array $argv = []): array
{
    $live = phalanxAthenaExampleLiveMode();
    $context = [
        'argv' => $argv,
        'ATHENA_DEMO_LIVE' => $live,
    ];

    foreach (phalanxAthenaExampleContextMap() as $env => $key) {
        if (!$live && in_array($env, phalanxAthenaExampleLiveKeys(), true)) {
            continue;
        }

        $value = getenv($env);

        if ($value === false || $value === '') {
            continue;
        }

        $context[$key] = $env === 'OLLAMA_ENABLED'
            ? filter_var($value, FILTER_VALIDATE_BOOL)
            : $value;
    }

    $ollamaBaseUrl = (string) ($context['OLLAMA_BASE_URL'] ?? 'http://localhost:11434');

    if (phalanxAthenaExampleCanConnect($ollamaBaseUrl)) {
        if (!array_key_exists('OLLAMA_ENABLED', $context)) {
            $context['OLLAMA_ENABLED'] = true;
        }

        if (!array_key_exists('OLLAMA_MODEL', $context)) {
            $model = phalanxAthenaExampleOllamaModel($ollamaBaseUrl);

            if ($model !== null) {
                $context['OLLAMA_MODEL'] = $model;
            }
        }
    }

    return $context;
}

function phalanxAthenaExampleLiveMode(): bool
{
    return filter_var(getenv('ATHENA_DEMO_LIVE') ?: false, FILTER_VALIDATE_BOOL);
}

function phalanxAthenaExampleEnvStatus(string $env, bool $requiresLive = false): string
{
    $value = getenv($env);

    if ($value === false || $value === '') {
        return 'missing';
    }

    if ($requiresLive && !phalanxAthenaExampleLiveMode()) {
        return 'set but ignored; set ATHENA_DEMO_LIVE=1';
    }

    return 'present';
}

function phalanxAthenaExampleComposerCommand(string $rootScript, string $packageScript): string
{
    $packageRoot = realpath(dirname(__DIR__));
    $workingDirectory = realpath(getcwd() ?: '.');

    if ($packageRoot !== false && $workingDirectory === $packageRoot) {
        return 'composer ' . $packageScript;
    }

    return 'composer ' . $rootScript;
}

function phalanxAthenaExamplePrintServerFailure(\Throwable $e, string $listen): void
{
    echo "\nServer failed before accepting requests.\n\n";

    if (str_contains($e->getMessage(), 'Address already in use')) {
        printf("Cause: %s is already in use.\n", $listen);
        echo "Action: stop the other server using that port, then rerun this demo.\n";
        return;
    }

    printf("Cause: %s\n", $e->getMessage());
}

function phalanxAthenaExampleCannotRun(string $title, string $reason, string $fix): never
{
    printf("%s\n", $title);
    printf("%s\n", str_repeat('=', strlen($title)));
    echo "Status: cannot run\n\n";
    printf("Missing requirement: %s\n\n", $reason);
    printf("Fix: %s\n", $fix);
    exit(0);
}

function phalanxAthenaExampleCanConnect(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);

    if (!is_string($host)) {
        return false;
    }

    $socket = @fsockopen(
        $host,
        is_int($port) ? $port : 80,
        $errorCode,
        $errorMessage,
        0.15,
    );

    if ($socket === false) {
        return false;
    }

    fclose($socket);
    return true;
}

function phalanxAthenaExampleOllamaModel(string $baseUrl): ?string
{
    $json = phalanxAthenaExampleHttpGet(rtrim($baseUrl, '/') . '/api/tags');

    if ($json === null) {
        return null;
    }

    $payload = json_decode($json, true);

    if (!is_array($payload) || !isset($payload['models']) || !is_array($payload['models'])) {
        return null;
    }

    foreach ($payload['models'] as $model) {
        if (!is_array($model)) {
            continue;
        }

        $name = $model['name'] ?? null;
        $details = $model['details'] ?? [];
        $family = is_array($details) ? (string) ($details['family'] ?? '') : '';

        if (is_string($name) && $name !== '' && !str_contains($family, 'bert')) {
            return $name;
        }
    }

    return null;
}

function phalanxAthenaExampleHttpGet(string $url): ?string
{
    set_error_handler(static fn() => true);
    $response = file_get_contents($url);
    restore_error_handler();

    return is_string($response) ? $response : null;
}

/** @return list<string> */
function phalanxAthenaExampleLiveKeys(): array
{
    return [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'OPENAI_BASE_URL',
        'GEMINI_API_KEY',
        'GEMINI_MODEL',
        'GUZZLE_DEMO_URL',
    ];
}

/** @return array<string, string> */
function phalanxAthenaExampleContextMap(): array
{
    return [
        'ANTHROPIC_API_KEY' => 'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY' => 'OPENAI_API_KEY',
        'OPENAI_BASE_URL' => 'OPENAI_BASE_URL',
        'GEMINI_API_KEY' => 'GEMINI_API_KEY',
        'GEMINI_MODEL' => 'GEMINI_MODEL',
        'OLLAMA_ENABLED' => 'OLLAMA_ENABLED',
        'OLLAMA_BASE_URL' => 'OLLAMA_BASE_URL',
        'OLLAMA_MODEL' => 'OLLAMA_MODEL',
        'GUZZLE_DEMO_URL' => 'GUZZLE_DEMO_URL',
        'DAEMON8_URL' => 'DAEMON8_URL',
        'DAEMON8_APP' => 'DAEMON8_APP',
        'SWARM_SESSION' => 'SWARM_SESSION',
        'SWARM_WORKSPACE' => 'SWARM_WORKSPACE',
        'REDIS_URL' => 'redis_url',
        'REDIS_HOST' => 'redis_host',
        'REDIS_PORT' => 'redis_port',
        'REDIS_PASSWORD' => 'redis_password',
        'REDIS_DATABASE' => 'redis_database',
        'DATABASE_URL' => 'database_url',
        'PG_HOST' => 'pg_host',
        'PG_PORT' => 'pg_port',
        'PG_USER' => 'pg_user',
        'PG_PASSWORD' => 'pg_password',
        'PG_DATABASE' => 'pg_database',
    ];
}

<?php

declare(strict_types=1);

/**
 * @param list<string> $argv
 * @return array<string, mixed>
 */
function phalanxAthenaExampleContext(array $argv = []): array
{
    $live = filter_var(getenv('ATHENA_DEMO_LIVE') ?: false, FILTER_VALIDATE_BOOL);
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

    return $context;
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

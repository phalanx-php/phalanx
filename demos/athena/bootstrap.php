<?php

declare(strict_types=1);

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Athena\Support\DemoContextKeys;
use Phalanx\Demos\Athena\Support\LiveModeFlag;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function phalanxAthenaExampleContext(array $argv = []): array
{
    unset($argv);

    $keys = [
        DemoContextKeys::GEMINI_MODEL,
        DemoContextKeys::OPENAI_BASE_URL,
        DemoContextKeys::OPENAI_API_KEY,
        DemoContextKeys::GEMINI_API_KEY,
        DemoContextKeys::OLLAMA_MODEL,
        DemoContextKeys::OLLAMA_BASE_URL,
        DemoContextKeys::OLLAMA_ENABLED,
        DemoContextKeys::ANTHROPIC_API_KEY,
        DemoContextKeys::ATHENA_DEMO_LIVE,
    ];

    $values = [];
    foreach ($keys as $key) {
        $value = getenv($key);

        if ($value !== false) {
            $values[$key] = $value;
        }
    }

    return (new LiveModeFlag(new AppContext($values)))
        ->effective()
        ->values;
}

function phalanxAthenaExampleLiveMode(): bool
{
    $value = getenv(DemoContextKeys::ATHENA_DEMO_LIVE);

    return in_array($value, ['1', 'true', 'on', 'yes'], true);
}

function phalanxAthenaExampleEnvStatus(string $key, bool $requiresLive = false): string
{
    $present = getenv($key) !== false && getenv($key) !== '';

    if ($requiresLive && !phalanxAthenaExampleLiveMode()) {
        return $present ? 'present, ignored until live mode is enabled' : 'not set';
    }

    return $present ? 'set' : 'not set';
}

function phalanxAthenaExampleComposerCommand(string $script): string
{
    return "composer {$script}";
}

function phalanxAthenaExamplePrintServerFailure(Throwable $e, string $listen): void
{
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, "Support triage server failed on {$listen}." . PHP_EOL);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
}

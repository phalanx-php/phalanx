<?php

declare(strict_types=1);

namespace Phalanx\Demos\Athena\Support;

/**
 * Canonical context-key constants and the live/non-live key split
 * for Athena examples.
 *
 * Live keys require ATHENA_DEMO_LIVE=1; they are silently omitted
 * from the context when live mode is off so demos run safely without
 * real API keys present.
 */
final class DemoContextKeys
{
    // Provider keys
    public const string ANTHROPIC_API_KEY = 'ANTHROPIC_API_KEY';
    public const string OPENAI_API_KEY    = 'OPENAI_API_KEY';
    public const string OPENAI_BASE_URL   = 'OPENAI_BASE_URL';
    public const string GEMINI_API_KEY    = 'GEMINI_API_KEY';
    public const string GEMINI_MODEL      = 'GEMINI_MODEL';

    // Ollama (local — not live-only)
    public const string OLLAMA_ENABLED  = 'OLLAMA_ENABLED';
    public const string OLLAMA_BASE_URL = 'OLLAMA_BASE_URL';
    public const string OLLAMA_MODEL    = 'OLLAMA_MODEL';

    // Guzzle demo
    public const string GUZZLE_DEMO_URL = 'GUZZLE_DEMO_URL';

    // Feature flag
    public const string ATHENA_DEMO_LIVE = 'ATHENA_DEMO_LIVE';

    /**
     * Keys that require ATHENA_DEMO_LIVE=1.
     * Filtered out of the effective context when live mode is off.
     *
     * @return list<string>
     */
    public static function liveKeys(): array
    {
        return [
            self::ANTHROPIC_API_KEY,
            self::OPENAI_API_KEY,
            self::OPENAI_BASE_URL,
            self::GEMINI_API_KEY,
            self::GEMINI_MODEL,
            self::GUZZLE_DEMO_URL,
        ];
    }

    private function __construct()
    {
    }
}

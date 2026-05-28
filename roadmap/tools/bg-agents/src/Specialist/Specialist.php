<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

/**
 * Static configuration of a specialist, loaded once at boot.
 *
 * Stateless: every call rebuilds its own ContextPack from this spec plus
 * fresh world data. There is no per-conversation history — that's the
 * whole point of "background specialists" as designed.
 */
final readonly class Specialist
{
    /**
     * @param list<string> $addressing  e.g. ['@runtime', '@platform']
     * @param list<string> $ragTags
     * @param list<string> $ragTopics
     */
    public function __construct(
        public string $name,
        public array $addressing,
        public string $provider,
        public string $model,
        public float $temperature,
        public string $identityPrompt,
        public SubscriptionFilter $subscription,
        public array $ragTags,
        public array $ragTopics,
        public string $description,
        public string $sourcePath,
    ) {}
}

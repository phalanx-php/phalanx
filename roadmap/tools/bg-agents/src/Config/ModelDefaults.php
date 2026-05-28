<?php

declare(strict_types=1);

namespace BgAgents\Config;

final readonly class ModelDefaults
{
    public function __construct(
        public string $supervisor,
        public string $specialist,
        public string $bookkeeperConsolidate,
        public string $bookkeeperPromote,
    ) {}

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self(
            supervisor: (string) ($context['BG_AGENTS_MODEL_SUPERVISOR'] ?? 'claude-opus-4-7'),
            specialist: (string) ($context['BG_AGENTS_MODEL_SPECIALIST'] ?? 'claude-sonnet-4-6'),
            bookkeeperConsolidate: (string) ($context['BG_AGENTS_MODEL_CONSOLIDATE'] ?? 'gemini-2.0-flash'),
            bookkeeperPromote: (string) ($context['BG_AGENTS_MODEL_PROMOTE'] ?? 'claude-sonnet-4-6'),
        );
    }
}

<?php

declare(strict_types=1);

namespace BgAgents\Config;

final readonly class BgAgentsConfig
{
    public function __construct(
        public string $daemon8Url,
        public string $app,
        public string $workspace,
        public string $session,
        public string $specsDir,
        public string $projectRoot,
        public ModelDefaults $models,
        public bool $verbose,
    ) {}

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        $projectRoot = rtrim((string) ($context['BG_AGENTS_PROJECT_ROOT'] ?? getcwd()), '/');

        return new self(
            daemon8Url: rtrim((string) ($context['DAEMON8_URL'] ?? 'http://localhost:8888'), '/'),
            app: (string) ($context['DAEMON8_APP'] ?? 'bg-agents'),
            workspace: (string) ($context['SWARM_WORKSPACE'] ?? basename($projectRoot)),
            session: (string) ($context['SWARM_SESSION'] ?? 'bg-agents-' . substr(bin2hex(random_bytes(4)), 0, 8)),
            specsDir: rtrim((string) ($context['BG_AGENTS_SPECS_DIR'] ?? dirname(__DIR__, 2) . '/specs'), '/'),
            projectRoot: $projectRoot,
            models: ModelDefaults::fromContext($context),
            verbose: filter_var($context['BG_AGENTS_VERBOSE'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Agentic;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Agentic\Supervisor\ConversationSupervisor;
use Phalanx\Eidolon\Signal\SignalCollector;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AgenticServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->scoped(SignalCollector::class)
            ->factory(static fn(): SignalCollector => new SignalCollector());

        $services->scoped(\Phalanx\Agentic\Composer\SlashCommandRegistry::class)
            ->factory(static fn(): \Phalanx\Agentic\Composer\SlashCommandRegistry => new \Phalanx\Agentic\Composer\SlashCommandRegistry());

        $services->scoped(\Phalanx\Agentic\Composer\AttachmentSession::class)
            ->factory(static fn(): \Phalanx\Agentic\Composer\AttachmentSession => new \Phalanx\Agentic\Composer\AttachmentSession());

        $services->scoped(AgentSessionRegistry::class)
            ->factory(static fn(): AgentSessionRegistry => new AgentSessionRegistry());

        // Supervisor is started manually via Athena::starting or Stoa runner
        $services->scoped(ConversationSupervisor::class)
            ->lazy()
            ->factory(static fn(): ConversationSupervisor => new ConversationSupervisor(
                workspace: $context['agentic_workspace'] ?? 'global',
            ));
    }
}

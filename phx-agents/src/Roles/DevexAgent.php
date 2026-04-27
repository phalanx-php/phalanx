<?php
declare(strict_types=1);

namespace Phx\Agents\Roles;

use Phalanx\Athena\AgentDefinition;

final class DevexAgent implements AgentDefinition
{
    public string $instructions {
        get => file_get_contents('/Users/jhavens/.obsidian/vaults/aimind/AIMind/35-multiagent/playbooks/daemon8-brainstorm-team/personas/devex.persona.md');
    }

    public function tools(): array { return []; }
    public function provider(): ?string { return 'gemini'; }
    public function __invoke(\Phalanx\ExecutionScope $scope): mixed { return null; }
    public function model(): ?string { return 'gemini-1.5-flash'; }
}

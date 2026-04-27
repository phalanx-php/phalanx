<?php
declare(strict_types=1);

namespace Phx\Agents\Roles;

use Phalanx\Athena\AgentDefinition;

final class VisionAgent implements AgentDefinition
{
    public string $instructions {
        get => "You are VISION. Keep responses short.";
    }

    public function tools(): array { return []; }
    public function provider(): ?string { return 'gemini'; }
    public function __invoke(\Phalanx\ExecutionScope $scope): mixed { return null; }
    public function model(): ?string { return 'gemini-flash-lite-latest'; }
}

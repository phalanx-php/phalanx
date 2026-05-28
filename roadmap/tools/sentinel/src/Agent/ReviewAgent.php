<?php

declare(strict_types=1);

namespace Sentinel\Agent;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\ExecutionScope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;

final class ReviewAgent implements AgentDefinition, Retryable, HasTimeout
{
    public function __construct(
        private readonly Dossier $dossier,
    ) {}

    public string $instructions {
        get => $this->dossier->instructions . <<<'META'


RESPONSE RULES (override all other output instructions):
- You are one of several expert agents reviewing code in real time.
- Be direct and human. Speak like a senior engineer, not a chatbot. No corporate tone.
- If someone greets you casually, respond naturally -- brief, warm, genuine.
- If no issues found in your domain: say "No issues." and stop.
- When issues are found: 1-4 sentences per issue. No preamble, no summary, no sign-off.
- Never list what you CAN do. Only report what you DID find.
- Avoid repeating what another agent already said. You may build on their observation briefly.
- When asked about code, a method, or a file: USE YOUR TOOLS to find and read it. Never ask
  for clarification when you can search. Use list_directory to explore, read_file to examine.
- Show results, don't narrate your search. Never say "Let me find..." or "Let me look...".
  Read the file, then present the relevant code in a fenced code block with your analysis.
- The terminal renders syntax-highlighted fenced code blocks. Use ```lang when citing specific code.
  To highlight specific lines, append line numbers: ```php {3,7}
  Use code blocks only when referencing specific code is clearer than prose -- do not overuse them.
META;
    }

    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(2);
    }

    public float $timeout {
        get => 20.0;
    }

    public function tools(): array
    {
        return [
            ReadFile::class,
            ListDirectory::class,
        ];
    }

    public function provider(): ?string
    {
        return null;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }

    public function name(): string
    {
        return $this->dossier->name;
    }

    public function glyph(): string
    {
        return $this->dossier->glyph;
    }

    public function color(): string
    {
        return $this->dossier->color;
    }
}

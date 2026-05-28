<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

/**
 * The fully-assembled per-query context for a stateless specialist call.
 *
 * Discarded after each call. The four chunks are kept separate so a debug
 * mode (--debug-context) can show them individually before the LLM call.
 */
final readonly class ContextPack
{
    /**
     * @param list<string> $observationLines  one bullet per recent observation
     * @param list<string> $ragLines          one bullet per RAG memory record
     */
    public function __construct(
        public Specialist $specialist,
        public string $situational,
        public array $observationLines,
        public array $ragLines,
    ) {}

    public function renderSystemPrompt(): string
    {
        $sections = [
            "# Identity",
            $this->specialist->identityPrompt,
        ];

        if ($this->observationLines !== []) {
            $sections[] = "# Live Observations (recent daemon8 stream slice)";
            $sections[] = implode("\n", array_map(static fn(string $l): string => "- {$l}", $this->observationLines));
        } else {
            $sections[] = "# Live Observations\n(none in subscription window)";
        }

        if ($this->ragLines !== []) {
            $sections[] = "# Long-term Memory (RAG)";
            $sections[] = implode("\n", array_map(static fn(string $l): string => "- {$l}", $this->ragLines));
        }

        $sections[] = "# Situational Input\nThe user's prompt follows in the user message. Answer with the identity above, grounded in the live observations and memory.";

        return implode("\n\n", $sections);
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Cue\Usage;

use Phalanx\AiProviders\Cue;

/**
 * Final usage tally for one invocation. Named `FinalUsage` rather than
 * `Final` to avoid the PHP reserved keyword collision; `type` remains
 * `cue.usage.final`.
 */
final class FinalUsage extends Cue
{
    final public string $type { get => 'cue.usage.final'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) int $inputTokens,
        private(set) int $outputTokens,
        private(set) int $cacheReadTokens = 0,
        private(set) int $cacheWriteTokens = 0,
        private(set) ?float $costUsd = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cost_usd' => $this->costUsd,
        ];
    }
}

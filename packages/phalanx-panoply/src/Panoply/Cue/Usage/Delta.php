<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Usage;

use Phalanx\Panoply\Cue;

final class Delta extends Cue
{
    public string $type { get => 'cue.usage.delta'; }

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
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'input_tokens'       => $this->inputTokens,
            'output_tokens'      => $this->outputTokens,
            'cache_read_tokens'  => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }
}

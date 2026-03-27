<?php

declare(strict_types=1);

namespace Phalanx\Ai;

use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Tool\ToolCallBag;

final readonly class StepResult
{
    public function __construct(
        public int $number,
        public string $text,
        public ToolCallBag $toolCalls,
        public TokenUsage $usage,
    ) {}
}

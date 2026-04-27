<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Tool\ToolCallBag;

final readonly class StepResult
{
    public function __construct(
        public int $number,
        public string $text,
        public ToolCallBag $toolCalls,
        public TokenUsage $usage,
    ) {}
}

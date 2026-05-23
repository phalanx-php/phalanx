<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Support;

use Phalanx\Panoply\Cue;
use Phalanx\Theatron\Agent\AgentExecutorContract;
use Phalanx\Theatron\Template\Slice\PendingEffect;

final class RecordingAgentExecutor implements AgentExecutorContract
{
    /** @var list<string> */
    public array $sentMessages = [];

    /** @var list<PendingEffect> */
    public array $approvedEffects = [];

    /** @var list<PendingEffect> */
    public array $deniedEffects = [];

    public int $cancelCount = 0;

    /**
     * @param list<Cue> $sendCues
     * @param list<Cue> $approvalCues
     * @param list<Cue> $denialCues
     */
    public function __construct(
        private array $sendCues = [],
        private array $approvalCues = [],
        private array $denialCues = [],
    ) {
    }

    /** @return iterable<Cue> */
    public function send(string $message): iterable
    {
        $this->sentMessages[] = $message;

        yield from $this->sendCues;
    }

    /** @return iterable<Cue> */
    public function approve(PendingEffect $effect): iterable
    {
        $this->approvedEffects[] = $effect;

        yield from $this->approvalCues;
    }

    /** @return iterable<Cue> */
    public function deny(PendingEffect $effect): iterable
    {
        $this->deniedEffects[] = $effect;

        yield from $this->denialCues;
    }

    public function cancel(): void
    {
        $this->cancelCount++;
    }
}

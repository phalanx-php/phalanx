<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Harness\Template\Slice\PendingEffect;

final class MockAgentExecutor implements AgentExecutorContract
{
    private bool $cancelled = false;

    /**
     * @param list<\Phalanx\Panoply\Cue> $scriptedCues
     * @param list<\Phalanx\Panoply\Cue> $approvalCues
     * @param list<\Phalanx\Panoply\Cue> $denialCues
     */
    public function __construct(
        private(set) array $scriptedCues = [],
        private(set) array $approvalCues = [],
        private(set) array $denialCues = [],
    ) {
    }

    /** @return iterable<\Phalanx\Panoply\Cue> */
    public function send(string $message): iterable
    {
        yield from $this->play($this->scriptedCues);
    }

    /** @return iterable<\Phalanx\Panoply\Cue> */
    public function approve(PendingEffect $effect): iterable
    {
        yield from $this->play($this->approvalCues);
    }

    /** @return iterable<\Phalanx\Panoply\Cue> */
    public function deny(PendingEffect $effect): iterable
    {
        yield from $this->play($this->denialCues);
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * @param list<\Phalanx\Panoply\Cue> $cues
     */
    public function withCues(array $cues): self
    {
        return new self($cues, $this->approvalCues, $this->denialCues);
    }

    /**
     * @param list<\Phalanx\Panoply\Cue> $cues
     */
    public function withApprovalCues(array $cues): self
    {
        return new self($this->scriptedCues, $cues, $this->denialCues);
    }

    /**
     * @param list<\Phalanx\Panoply\Cue> $cues
     */
    public function withDenialCues(array $cues): self
    {
        return new self($this->scriptedCues, $this->approvalCues, $cues);
    }

    /**
     * @param list<\Phalanx\Panoply\Cue> $cues
     * @return iterable<\Phalanx\Panoply\Cue>
     */
    private function play(array $cues): iterable
    {
        $this->reset();

        foreach ($cues as $cue) {
            yield $cue;

            if ($this->cancelled) {
                return;
            }
        }
    }

    private function reset(): void
    {
        $this->cancelled = false;
    }
}

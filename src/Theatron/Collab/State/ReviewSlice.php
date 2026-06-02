<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;

final class ReviewSlice
{
    /** @var list<ReviewVerdict> */
    private(set) array $verdicts;

    /**
     * @param list<ReviewVerdict> $verdicts
     */
    public function __construct(array $verdicts = [])
    {
        $this->verdicts = array_values($verdicts);
    }

    public function record(ReviewVerdict $verdict): self
    {
        return new self([...$this->verdicts, $verdict]);
    }
}

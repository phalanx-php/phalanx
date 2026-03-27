<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Fixtures;

use Phalanx\Ai\Schema\Structured;
use Phalanx\Ai\Tool\Param;

#[Structured(description: 'Sentiment analysis result')]
final readonly class SentimentResult
{
    public function __construct(
        #[Param('The detected sentiment')]
        public SentimentKind $sentiment,
        #[Param('Confidence score between 0 and 1')]
        public float $confidence,
        #[Param('Brief explanation of the classification')]
        public string $reasoning,
    ) {}
}

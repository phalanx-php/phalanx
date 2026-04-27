<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

enum SentimentKind: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
    case Mixed = 'mixed';
}

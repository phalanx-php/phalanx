<?php

declare(strict_types=1);

namespace Phalanx\Agents\Exception;

final class MaxInvocationsReached extends \RuntimeException
{
    public function __construct(private(set) string $activityId, private(set) int $maxInvocations)
    {
        parent::__construct(sprintf(
            'Activity %s reached the maximum of %d invocations.',
            $this->activityId,
            $this->maxInvocations,
        ));
    }
}

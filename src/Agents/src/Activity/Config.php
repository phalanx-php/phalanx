<?php

declare(strict_types=1);

namespace Phalanx\Agents\Activity;

use Phalanx\Agents\Hook\StepHook;
use Phalanx\AiProviders\Context;

final class Config
{
    /** @var list<StepHook> */
    private(set) array $hooks;

    /**
     * @param list<StepHook> $hooks
     */
    public function __construct(
        private(set) string $id,
        private(set) Context $context,
        private(set) int $maxInvocations = 3,
        private(set) ?float $timeoutSeconds = null,
        array $hooks = [],
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('Activity id cannot be empty.');
        }

        if ($this->maxInvocations < 1) {
            throw new \InvalidArgumentException('Activity maxInvocations must be >= 1.');
        }

        if ($this->timeoutSeconds !== null && $this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Activity timeoutSeconds must be > 0.');
        }

        foreach ($hooks as $hook) {
            if (!$hook instanceof StepHook) {
                throw new \InvalidArgumentException('Activity hooks must be StepHook instances.');
            }
        }

        $this->hooks = $hooks;
    }
}

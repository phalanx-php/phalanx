<?php

declare(strict_types=1);

namespace Phalanx\Agents\Hook;

use Phalanx\Scope\TaskScope;

final class StepHookChain
{
    /** @param list<StepHook> $hooks */
    public function __construct(
        private array $hooks = [],
    ) {
    }

    public function notify(TaskScope $scope, StepContext $context): StepHookResult
    {
        foreach ($this->hooks as $hook) {
            $result = $hook($scope, $context);

            if ($result->outcome->terminal()) {
                return $result;
            }
        }

        return StepHookResult::continue();
    }
}

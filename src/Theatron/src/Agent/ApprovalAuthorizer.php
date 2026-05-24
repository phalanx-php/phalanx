<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Grant;

final class ApprovalAuthorizer implements Authorizer
{
    public function __construct(
        private(set) Authorizer $inner,
    ) {
    }

    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        if ($effect->requiresApproval && $grant === null) {
            return Decision::paused('Approval required');
        }

        return $this->inner->evaluate($effect, $grant);
    }
}

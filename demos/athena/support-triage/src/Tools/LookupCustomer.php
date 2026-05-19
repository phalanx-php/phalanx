<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Scope\TaskScope;
use Phalanx\SelfDescribed;

final class LookupCustomer implements Tool, SelfDescribed
{
    public string $description {
        get => 'Look up a customer account by email or account ID, returning profile details and recent activity history.';
    }

    public function __construct(
        #[Param('Customer email address or account ID')]
        private readonly string $identifier,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'customer' => [
                'id'     => 42,
                'email'  => $this->identifier,
                'name'   => 'Leonidas of Sparta',
                'plan'   => 'Professional',
                'mrr'    => 99.00,
                'status' => 'active',
            ],
            'recent_activity' => [
                ['action' => 'login', 'created_at' => '2026-05-15T09:00:00Z'],
                ['action' => 'view_phalanx_docs', 'created_at' => '2026-05-15T09:10:00Z'],
            ],
        ]);
    }
}

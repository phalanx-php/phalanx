<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Agents\Effect\Context as EffectContext;
use Phalanx\Agents\Effect\Outcome as EffectOutcome;
use Phalanx\Agents\Effect\Resolution;
use Phalanx\Agents\Tool\Param;
use Phalanx\Agents\Tool\Tool;
use Phalanx\Scope\TaskScope;
use Phalanx\SelfDescribed;

final class GetRecentTickets implements Tool, SelfDescribed
{
    public string $description {
        get => 'Retrieve the most recent support tickets for a customer by their account ID.';
    }


    public function __construct(
        #[Param('Customer ID')]
        private(set) int $customerId,
        #[Param('Number of recent tickets')]
        private(set) int $limit = 5,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        $tickets = [
            ['id' => 501, 'subject' => 'Sarissa length inconsistency', 'status' => 'resolved', 'created_at' => '2026-04-10'],
            ['id' => 508, 'subject' => 'Hoplite formation spacing', 'status' => 'open', 'created_at' => '2026-04-12'],
            ['id' => 519, 'subject' => 'Battle order delivery to Marathon', 'status' => 'pending', 'created_at' => '2026-05-01'],
        ];

        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'customer_id' => $this->customerId,
            'tickets'     => array_slice($tickets, 0, $this->limit),
        ]);
    }
}

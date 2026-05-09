<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

final class GetRecentTickets implements Tool
{
    public string $description {
        get => 'Get recent support tickets from this customer';
    }

    public function __construct(
        #[Param('Customer ID')]
        private(set) int $customerId,
        #[Param('Number of recent tickets')]
        private(set) int $limit = 5,
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        $tickets = [
            ['id' => 501, 'subject' => 'Athena epithet clarification', 'status' => 'resolved', 'created_at' => '2026-03-10'],
            ['id' => 508, 'subject' => 'Wisdom exhibit labels', 'status' => 'open', 'created_at' => '2026-03-12'],
            ['id' => 519, 'subject' => 'Strategy tour accessibility', 'status' => 'pending', 'created_at' => '2026-03-14'],
        ];

        return ToolOutcome::data([
            'customer_id' => $this->customerId,
            'tickets' => array_slice($tickets, 0, $this->limit),
        ]);
    }
}

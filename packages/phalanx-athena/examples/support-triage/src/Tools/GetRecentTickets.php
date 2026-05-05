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
        private readonly int $customerId,
        #[Param('Number of recent tickets')]
        private readonly int $limit = 5,
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data([
            ['id' => 501, 'subject' => 'Export timeout', 'status' => 'resolved', 'created_at' => '2026-03-10'],
        ]);
    }
}

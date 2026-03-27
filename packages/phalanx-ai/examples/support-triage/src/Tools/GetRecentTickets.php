<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

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
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production: PgPool query
        return ToolOutcome::data([
            ['id' => 501, 'subject' => 'Export timeout', 'status' => 'resolved', 'created_at' => '2026-03-10'],
        ]);
    }
}

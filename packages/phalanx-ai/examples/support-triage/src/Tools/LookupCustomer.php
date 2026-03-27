<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class LookupCustomer implements Tool
{
    public string $description {
        get => 'Look up customer account details by email or ID';
    }

    public function __construct(
        #[Param('Customer email address or account ID')]
        private readonly string $identifier,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production: $scope->service(PgPool::class)->query(...)
        return ToolOutcome::data([
            'customer' => [
                'id' => 42,
                'email' => $this->identifier,
                'name' => 'Sarah Johnson',
                'plan' => 'Professional',
                'mrr' => 99.00,
                'status' => 'active',
            ],
            'recent_activity' => [
                ['action' => 'login', 'created_at' => '2026-03-25T14:30:00Z'],
                ['action' => 'export_report', 'created_at' => '2026-03-25T14:35:00Z'],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Triggers a real-time handoff to a human support agent via pub/sub.
 *
 * Returns ToolOutcome::done() which terminates the agent loop cleanly.
 * The AI's final message tells the customer a human is coming. The pub/sub
 * message notifies the gateway process to update the human agent dashboard.
 */
final class TransferToHuman implements Tool
{
    public string $description {
        get => 'Transfer this conversation to a human support agent when you cannot ' .
               'resolve the issue or the customer explicitly requests a human';
    }

    public function __construct(
        #[Param('Why this conversation needs a human agent')]
        private readonly string $reason,
        #[Param('The department to route to: general, billing, technical, management')]
        private readonly string $department,
        #[Param('Brief context summary for the human agent')]
        private readonly string $contextSummary,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production:
        //   $sessionId = $scope->attribute('session.id');
        //   $tenantId = $scope->attribute('tenant.id');
        //
        //   $scope->service(PgPool::class)->execute(
        //       'INSERT INTO escalations ...', [...]
        //   );
        //
        //   $scope->service(RedisPubSub::class)->publish(
        //       "tenant:{$tenantId}:escalations",
        //       json_encode([...])
        //   );

        return ToolOutcome::done(
            "I'm connecting you with a {$this->department} specialist now. " .
            "They'll have the full context of our conversation. " .
            "Please hold on for just a moment.",
            reason: 'Transferred to human agent',
        );
    }
}

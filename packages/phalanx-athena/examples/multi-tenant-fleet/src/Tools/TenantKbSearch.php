<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

/**
 * Searches the tenant-specific knowledge base.
 *
 * Each tenant has their own KB articles in the database. The tenant ID
 * comes from the scope's attributes, set during the WebSocket connection
 * handshake.
 */
final class TenantKbSearch implements Tool
{
    public string $description {
        get => 'Search the tenant knowledge base for relevant articles';
    }

    public function __construct(
        #[Param('Search query')]
        private readonly string $query,
        #[Param('Maximum results')]
        private readonly int $limit = 3,
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data([
            'articles' => [
                ['id' => 1, 'title' => 'Getting Started', 'relevance' => 0.95],
                ['id' => 2, 'title' => 'FAQ', 'relevance' => 0.82],
            ],
        ]);
    }
}

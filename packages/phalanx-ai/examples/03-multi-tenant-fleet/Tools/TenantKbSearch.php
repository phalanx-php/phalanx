<?php

declare(strict_types=1);

namespace Phalanx\Ai\Examples\MultiTenantFleet\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

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
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production:
        //   $tenantId = $scope->attribute('tenant.id');
        //   $articles = $scope->service(PgPool::class)->query(
        //       "SELECT ... FROM kb_articles WHERE tenant_id = $1 AND search_vector @@ ...",
        //       [$tenantId, $this->query, $this->limit]
        //   );

        return ToolOutcome::data([
            'articles' => [
                ['id' => 1, 'title' => 'Getting Started', 'relevance' => 0.95],
                ['id' => 2, 'title' => 'FAQ', 'relevance' => 0.82],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

final class SearchKnowledgeBase implements Tool
{
    public string $description {
        get => 'Search the knowledge base for articles matching a query';
    }

    public function __construct(
        #[Param('Search query describing the issue')]
        private readonly string $query,
        #[Param('Maximum articles to return')]
        private readonly int $limit = 3,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production: full-text search via PgPool with ts_rank
        return ToolOutcome::data([
            ['id' => 101, 'title' => 'Export Report Troubleshooting', 'relevance' => 0.92],
            ['id' => 204, 'title' => 'Data Export Formats Guide', 'relevance' => 0.78],
        ]);
    }
}

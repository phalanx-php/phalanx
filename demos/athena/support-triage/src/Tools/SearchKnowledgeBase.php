<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

final class SearchKnowledgeBase implements Tool
{
    public string $description {
        get => 'Search the knowledge base for articles matching a query';
    }

    public function __construct(
        #[Param('Search query describing the issue')]
        private(set) string $query,
        #[Param('Maximum articles to return')]
        private(set) int $limit = 3,
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        $articles = [
            ['id' => 101, 'title' => 'Athena and the Owl Symbol', 'relevance' => 0.92],
            ['id' => 204, 'title' => 'Athena as Strategist and Patron of Wisdom', 'relevance' => 0.78],
            ['id' => 337, 'title' => 'Explaining Greek Epithets in Museum Copy', 'relevance' => 0.64],
        ];

        return ToolOutcome::data([
            'query' => $this->query,
            'articles' => array_slice($articles, 0, $this->limit),
        ]);
    }
}

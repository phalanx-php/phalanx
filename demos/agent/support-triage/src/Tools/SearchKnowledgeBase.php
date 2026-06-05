<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Agent\Effect\Resolution;
use Phalanx\Agent\Tool\Param;
use Phalanx\Agent\Tool\Tool;
use Phalanx\Scope\TaskScope;
use Phalanx\SelfDescribed;

final class SearchKnowledgeBase implements Tool, SelfDescribed
{
    public string $description {
        get => 'Search the support knowledge base for articles relevant to a described issue, returning the top matching entries.';
    }


    public function __construct(
        #[Param('Search query describing the issue')]
        private(set) string $query,
        #[Param('Maximum articles to return')]
        private(set) int $limit = 3,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        $articles = [
            ['id' => 101, 'title' => 'The Phalanx Formation: coordination under pressure', 'relevance' => 0.92],
            ['id' => 204, 'title' => 'Doru maintenance and field replacement', 'relevance' => 0.78],
            ['id' => 337, 'title' => 'Aspis delivery timelines from the agora', 'relevance' => 0.64],
        ];

        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'query'    => $this->query,
            'articles' => array_slice($articles, 0, $this->limit),
        ]);
    }
}

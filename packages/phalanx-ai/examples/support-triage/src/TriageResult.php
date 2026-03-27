<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Ai\Schema\Structured;
use Phalanx\Ai\Tool\Param;

#[Structured(description: 'AI triage classification and draft response for a support ticket')]
final readonly class TriageResult
{
    /**
     * @param list<int> $relevantArticleIds
     */
    public function __construct(
        #[Param('Ticket priority level')]
        public TicketPriority $priority,
        #[Param('Ticket category')]
        public TicketCategory $category,
        #[Param('One-line summary of the issue')]
        public string $summary,
        #[Param('Draft response to send to the customer')]
        public string $draftResponse,
        #[Param('Whether the issue can be auto-resolved from KB articles')]
        public bool $autoResolvable,
        #[Param('Escalation instructions if priority is critical')]
        public ?string $escalationNote,
        #[Param('IDs of relevant knowledge base articles')]
        public array $relevantArticleIds,
    ) {}
}

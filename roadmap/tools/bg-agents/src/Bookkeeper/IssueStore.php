<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper;

use BgAgents\Daemon8\BgEvent;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;

/**
 * Owns the in-process Issue table for the current session AND mirrors each
 * raise to daemon8 as a BlackboardPost so other surfaces (and the bookkeeper
 * REPL handler in another process) can see them.
 *
 * Memory hygiene: $issues is keyed by Issue::$id and capped at 200; oldest
 * are dropped when the cap hits. The blackboard remains the source of truth.
 */
final class IssueStore
{
    /** @var array<string, Issue> */
    private array $issues = [];

    public function __construct(
        private readonly SwarmBus $bus,
        private readonly SwarmConfig $swarm,
        private readonly int $cap = 200,
    ) {}

    public function raise(Issue $issue): void
    {
        if (isset($this->issues[$issue->id])) {
            return;
        }

        if (count($this->issues) >= $this->cap) {
            $oldestKey = array_key_first($this->issues);
            if ($oldestKey !== null) {
                unset($this->issues[$oldestKey]);
            }
        }

        $this->issues[$issue->id] = $issue;

        $this->bus->emit(new SwarmEvent(
            from: 'bookkeeper',
            kind: SwarmEventKind::BlackboardPost,
            workspace: $this->swarm->workspace,
            session: $this->swarm->session,
            payload: [
                'bg_kind' => self::bgKindFor($issue->kind),
                'issue_id' => $issue->id,
                'issue_kind' => $issue->kind->value,
                'refs' => $issue->refs,
                'suggestion' => $issue->suggestion,
                'extra' => $issue->payload,
            ],
        ));
    }

    /** @return list<Issue> */
    public function all(): array
    {
        return array_values($this->issues);
    }

    public function count(): int
    {
        return count($this->issues);
    }

    private static function bgKindFor(IssueKind $kind): string
    {
        return match ($kind) {
            IssueKind::ConsolidationProposed => BgEvent::BOOKKEEPER_CONSOLIDATION_PROPOSED,
            IssueKind::PromotionProposed => BgEvent::BOOKKEEPER_PROMOTION_PROPOSED,
            default => BgEvent::BOOKKEEPER_ISSUE,
        };
    }
}

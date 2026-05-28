<?php

declare(strict_types=1);

namespace BgAgents\Daemon8;

/**
 * Discriminator values for SwarmEvent::$payload['bg_kind'].
 *
 * SwarmEventKind is a sealed enum upstream; we ride on
 * SwarmEventKind::BlackboardPost and namespace our own event taxonomy under
 * payload['bg_kind'] so bg-agents can be added without modifying phalanx-athena.
 */
final class BgEvent
{
    public const string USER_PROMPT_SUBMITTED = 'bg.user.prompt_submitted';
    public const string AGENT_INTENT_PROPOSAL = 'bg.agent.intent_proposal';
    public const string AGENT_FINAL_ANSWER = 'bg.agent.final_answer';
    public const string TEAM_STARTED = 'bg.team.started';
    public const string TEAM_STOPPED = 'bg.team.stopped';
    public const string TEAM_HEARTBEAT = 'bg.team.heartbeat';
    public const string BOOKKEEPER_ONLINE = 'bg.bookkeeper.online';
    public const string BOOKKEEPER_OFFLINE = 'bg.bookkeeper.offline';
    public const string BOOKKEEPER_ISSUE = 'bg.bookkeeper.issue';
    public const string BOOKKEEPER_CONSOLIDATION_PROPOSED = 'bg.bookkeeper.consolidation_proposed';
    public const string BOOKKEEPER_CONSOLIDATION_APPLIED = 'bg.bookkeeper.consolidation_applied';
    public const string BOOKKEEPER_CONSOLIDATION_DISMISSED = 'bg.bookkeeper.consolidation_dismissed';
    public const string BOOKKEEPER_PROMOTION_PROPOSED = 'bg.bookkeeper.promotion_proposed';
    public const string BOOKKEEPER_PROMOTION_APPLIED = 'bg.bookkeeper.promotion_applied';
    public const string BOOKKEEPER_PROMOTION_DISMISSED = 'bg.bookkeeper.promotion_dismissed';
    public const string MEMORY_RECORD = 'bg.memory.record';

    private function __construct() {}
}

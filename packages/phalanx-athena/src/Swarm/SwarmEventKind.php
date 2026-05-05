<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

/**
 * Standard event kinds for multi-agent swarm coordination.
 */
enum SwarmEventKind: string
{
    case Online = 'online';
    case Offline = 'offline';

    case PlanningStarted = 'planning_started';
    case PlanningProposal = 'planning_proposal';
    case PlanningQuestion = 'planning_question';
    case PlanningBlocked = 'planning_blocked';

    case MiddleMgmtFacilitate = 'middle_mgmt_facilitate';
    case FinalPlanRequested = 'final_plan_requested';
    case PlanApproved = 'plan_approved';
    case PlanRejected = 'plan_rejected';

    case ClearanceRequested = 'clearance_requested';
    case ClearanceGranted = 'clearance_granted';
    case ClearanceDenied = 'clearance_denied';

    case UiIntent = 'ui_intent';
    case UiRender = 'ui_render';

    case SummaryUpdate = 'summary_update';
    case BlackboardPost = 'blackboard_post';
}

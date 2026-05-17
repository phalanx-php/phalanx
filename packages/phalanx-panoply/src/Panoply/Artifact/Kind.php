<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Artifact;

/**
 * Normalized kinds of durable agent output. Hosts and Delphi-style
 * consumers route artifacts to renderers and stores based on this kind.
 * Use {@see self::Custom} with a domain-specific subtype carried on the
 * artifact's payload for vendor- or host-defined outputs.
 */
enum Kind: string
{
    case Thesis               = 'thesis';
    case InvestigationReport  = 'investigation-report';
    case HazardReport         = 'hazard-report';
    case GrantRequest         = 'grant-request';
    case ProofPlan            = 'proof-plan';
    case PatchDraft           = 'patch-draft';
    case ReviewFindings       = 'review-findings';
    case SourceSnapshot       = 'source-snapshot';
    case ConversationImport   = 'conversation-import';
    case ProviderHealthReport = 'provider-health-report';
    case StructuredResult     = 'structured-result';
    case Custom               = 'custom';
}

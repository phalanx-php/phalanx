<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Agent;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Decision;
use Phalanx\Panoply\Effect\Decision\Verdict;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Theatron\Agent\ApprovalAuthorizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApprovalAuthorizerTest extends TestCase
{
    #[Test]
    public function approvalRequiredEffectWithoutGrantPauses(): void
    {
        $inner = new RecordingInnerAuthorizer(Decision::granted('inner-grant'));
        $authorizer = new ApprovalAuthorizer($inner);

        $decision = $authorizer->evaluate(Effect::of(
            id: 'write_file',
            kind: Kind::FileWrite,
            summary: 'Write file',
            requiresApproval: true,
        ));

        self::assertSame(Verdict::Paused, $decision->verdict);
        self::assertSame('Approval required', $decision->pauseReason);
        self::assertSame(0, $inner->calls);
    }

    #[Test]
    public function approvalRequiredEffectWithGrantDelegatesToInnerAuthorizer(): void
    {
        $innerDecision = Decision::granted('inner-grant');
        $inner = new RecordingInnerAuthorizer($innerDecision);
        $authorizer = new ApprovalAuthorizer($inner);
        $grant = new Grant(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [Kind::FileWrite],
            scope: 'once',
            hazardCeiling: Hazard::Medium,
        );

        $effect = Effect::of(
            id: 'write_file',
            kind: Kind::FileWrite,
            summary: 'Write file',
            requiresApproval: true,
        )->withHazard(Hazard::Medium);
        $decision = $authorizer->evaluate(
            $effect,
            $grant,
        );

        self::assertSame($innerDecision, $decision);
        self::assertSame(1, $inner->calls);
        self::assertSame($effect, $inner->effects[0]);
        self::assertSame($grant, $inner->grants[0]);
    }

    #[Test]
    public function nonApprovalEffectDelegatesToInnerAuthorizer(): void
    {
        $innerDecision = Decision::denied('sentinel-denial');
        $inner = new RecordingInnerAuthorizer($innerDecision);
        $authorizer = new ApprovalAuthorizer($inner);

        $effect = Effect::of(
            id: 'read_file',
            kind: Kind::FileRead,
            summary: 'Read file',
            requiresApproval: false,
        );
        $decision = $authorizer->evaluate($effect);

        self::assertSame($innerDecision, $decision);
        self::assertSame(1, $inner->calls);
        self::assertSame($effect, $inner->effects[0]);
        self::assertNull($inner->grants[0]);
    }
}

final class RecordingInnerAuthorizer implements Authorizer
{
    public int $calls = 0;

    /** @var list<Effect> */
    public array $effects = [];

    /** @var list<?Grant> */
    public array $grants = [];

    public function __construct(private Decision $decision)
    {
    }

    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        $this->calls++;
        $this->effects[] = $effect;
        $this->grants[] = $grant;

        return $this->decision;
    }
}

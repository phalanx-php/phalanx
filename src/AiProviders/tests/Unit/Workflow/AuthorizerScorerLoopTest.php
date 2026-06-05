<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Workflow;

use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In-process Scorer → Authorizer workflow loop.
 * Verifies that the canonical ruleset correctly denies and grants across
 * the full combination of effect kind, grant surface, and hazard ceiling.
 *
 * Cross-reference: the v0 acceptance gate harness covers the same
 * Scorer/Authorizer surfaces at a coarser level via
 * {@see \Phalanx\AiProviders\Tests\Acceptance\V0AcceptanceGateTest::gate11RulesAuthorizerDeniesShellExecWithoutGrant()}
 * and gate12. These unit tests exercise the full decision matrix in depth.
 */
final class AuthorizerScorerLoopTest extends TestCase
{
    #[Test]
    public function shellExecIsHighHazard(): void
    {
        $effect = self::shellExecEffect();
        $scored = $effect->withHazard(new Scorer()->score($effect));

        self::assertSame(Hazard::High, $scored->hazard);
    }

    #[Test]
    public function grantPermittingFileReadOnlyDeniesShellExec(): void
    {
        $effect = self::shellExecEffect();
        $scored = $effect->withHazard(new Scorer()->score($effect));
        $grant = Grant::of(
            id: 'grant_agora_01',
            subject: 'leonidas',
            allowedEffects: [EffectKind::FileRead, EffectKind::CodeSearch],
            scope: 'polis.sparta',
            hazardCeiling: Hazard::Critical,
        );

        $decision = new Authorizer()->evaluate($scored, $grant);

        self::assertTrue($decision->isDenied());
        self::assertContains('effect-not-allowed', $decision->reasonCodes);
    }

    #[Test]
    public function grantPermittingShellExecButMediumCeilingDeniesHighHazard(): void
    {
        $effect = self::shellExecEffect();
        $scored = $effect->withHazard(new Scorer()->score($effect));

        $grant = Grant::of(
            id: 'grant_agora_02',
            subject: 'leonidas',
            allowedEffects: [EffectKind::ShellExec],
            scope: 'polis.sparta',
            hazardCeiling: Hazard::Medium,
        );

        $decision = new Authorizer()->evaluate($scored, $grant);

        self::assertTrue($decision->isDenied());
        self::assertContains('hazard-exceeds-ceiling', $decision->reasonCodes);
    }

    #[Test]
    public function grantPermittingShellExecWithCriticalCeilingGrants(): void
    {
        $effect = self::shellExecEffect();
        $scored = $effect->withHazard(new Scorer()->score($effect));

        $grant = Grant::of(
            id: 'grant_agora_03',
            subject: 'leonidas',
            allowedEffects: [EffectKind::ShellExec],
            scope: 'polis.sparta',
            hazardCeiling: Hazard::Critical,
        );

        $decision = new Authorizer()->evaluate($scored, $grant);

        self::assertTrue($decision->isGranted());
        self::assertSame('grant_agora_03', $decision->grantId);
    }

    #[Test]
    public function nullGrantAlwaysDeniesBeforeScoring(): void
    {
        $effect = self::shellExecEffect()->withHazard(Hazard::High);
        $decision = new Authorizer()->evaluate($effect, null);

        self::assertTrue($decision->isDenied());
        self::assertContains('no-grant', $decision->reasonCodes);
    }

    #[Test]
    public function lowHazardEffectGrantedUnderMediumCeiling(): void
    {
        $effect = Effect::of('eff_01', EffectKind::FileRead, 'read agora scrolls');
        $scored = $effect->withHazard(new Scorer()->score($effect));

        self::assertSame(Hazard::Low, $scored->hazard);

        $grant = Grant::of(
            id: 'grant_agora_04',
            subject: 'odysseus',
            allowedEffects: [EffectKind::FileRead],
            scope: 'polis.marathon',
            hazardCeiling: Hazard::Medium,
        );

        $decision = new Authorizer()->evaluate($scored, $grant);

        self::assertTrue($decision->isGranted());
    }

    #[Test]
    public function deterministicRoundtrip(): void
    {
        $scorer = new Scorer();
        $authorizer = new Authorizer();
        $effect = self::shellExecEffect();
        $grant = Grant::of(
            id: 'grant_determ',
            subject: 'leonidas',
            allowedEffects: [EffectKind::ShellExec],
            scope: 'thermopylae',
            hazardCeiling: Hazard::Critical,
        );

        $scored1 = $effect->withHazard($scorer->score($effect));
        $scored2 = $effect->withHazard($scorer->score($effect));
        $decision1 = $authorizer->evaluate($scored1, $grant);
        $decision2 = $authorizer->evaluate($scored2, $grant);

        self::assertSame($decision1->isGranted(), $decision2->isGranted());
        self::assertSame($decision1->grantId, $decision2->grantId);
    }

    private static function shellExecEffect(): Effect
    {
        return Effect::of(
            id: 'eff_thermopylae_01',
            kind: EffectKind::ShellExec,
            summary: 'Execute hoplite formation script',
            arguments: ['script' => 'deploy_agora.sh', 'target' => 'sparta'],
        );
    }
}

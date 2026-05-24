<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Effect\Authorizer\Rules;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthorizerTest extends TestCase
{
    #[Test]
    public function nullGrantProducesNoGrantDenial(): void
    {
        $decision = new Authorizer()->evaluate(self::effect(), null);

        self::assertTrue($decision->isDenied());
        self::assertContains('no-grant', $decision->reasonCodes);
    }

    #[Test]
    public function grantNotPermittingEffectKindProducesEffectNotAllowedDenial(): void
    {
        $grant = self::grant(allowedEffects: [EffectKind::FileRead]);
        $effect = Effect::of('eff_01', EffectKind::ShellExec, 'execute ls -la');
        $decision = new Authorizer()->evaluate($effect, $grant);

        self::assertTrue($decision->isDenied());
        self::assertContains('effect-not-allowed', $decision->reasonCodes);
    }

    #[Test]
    public function hazardExceedingCeilingProducesHazardExceedsCeilingDenial(): void
    {
        // Grant permits ShellExec but ceiling is Medium; ShellExec is High.
        $grant = self::grant(
            allowedEffects: [EffectKind::ShellExec],
            hazardCeiling: Hazard::Medium,
        );
        $effect = Effect::of('eff_01', EffectKind::ShellExec, 'rm -rf /')->withHazard(Hazard::High);

        $decision = new Authorizer()->evaluate($effect, $grant);

        self::assertTrue($decision->isDenied());
        self::assertContains('hazard-exceeds-ceiling', $decision->reasonCodes);
    }

    #[Test]
    public function expiredGrantProducesGrantExpiredDenial(): void
    {
        $now = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $grant = self::grant()->withExpiry(new \DateTimeImmutable('2026-05-17T11:00:00Z'));

        $decision = new Authorizer(now: $now)->evaluate(self::effect(), $grant);

        self::assertTrue($decision->isDenied());
        self::assertContains('grant-expired', $decision->reasonCodes);
    }

    #[Test]
    public function validGrantProducesGrantedDecision(): void
    {
        $now = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $grant = self::grant()->withExpiry(new \DateTimeImmutable('2026-05-17T13:00:00Z'));

        $decision = new Authorizer(now: $now)->evaluate(self::effect(), $grant);

        self::assertTrue($decision->isGranted());
        self::assertSame($grant->id, $decision->grantId);
    }

    #[Test]
    public function grantWithoutExpiryIsNotExpired(): void
    {
        $grant = self::grant();
        $decision = new Authorizer()->evaluate(self::effect(), $grant);

        self::assertTrue($decision->isGranted());
    }

    #[Test]
    public function deterministic(): void
    {
        $authorizer = new Authorizer(now: new \DateTimeImmutable('2026-05-17T12:00:00Z'));
        $grant = self::grant();
        $effect = self::effect();

        $d1 = $authorizer->evaluate($effect, $grant);
        $d2 = $authorizer->evaluate($effect, $grant);

        self::assertSame($d1->isGranted(), $d2->isGranted());
        self::assertSame($d1->grantId, $d2->grantId);
    }

    #[Test]
    public function nullGrantCheckPrecedesPermitCheck(): void
    {
        // null grant must deny before any effect-kind check fires.
        $decision = new Authorizer()->evaluate(
            Effect::of('eff_01', EffectKind::FileRead, 'list sources'),
            null,
        );

        self::assertContains('no-grant', $decision->reasonCodes);
        self::assertNotContains('effect-not-allowed', $decision->reasonCodes);
    }

    /**
     * @param list<EffectKind> $allowedEffects
     */
    private static function grant(
        array $allowedEffects = [],
        Hazard $hazardCeiling = Hazard::Critical,
    ): Grant {
        if ($allowedEffects === []) {
            $allowedEffects = [EffectKind::FileRead, EffectKind::CodeSearch];
        }

        return Grant::of(
            id: 'grant_agora_01',
            subject: 'leonidas',
            allowedEffects: $allowedEffects,
            scope: 'polis.sparta',
            hazardCeiling: $hazardCeiling,
        );
    }

    private static function effect(): Effect
    {
        return Effect::of(
            id: 'eff_agora_01',
            kind: EffectKind::FileRead,
            summary: 'Read the agora scrolls',
        );
    }
}

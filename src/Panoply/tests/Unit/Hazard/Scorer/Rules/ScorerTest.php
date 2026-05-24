<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Hazard\Scorer\Rules;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins spec acceptance gate #12.
 */
final class ScorerTest extends TestCase
{
    #[Test]
    public function fileReadIsLow(): void
    {
        self::assertSame(Hazard::Low, new Scorer()->score(self::effect(EffectKind::FileRead)));
    }

    #[Test]
    public function fileListIsLow(): void
    {
        self::assertSame(Hazard::Low, new Scorer()->score(self::effect(EffectKind::FileList)));
    }

    #[Test]
    public function codeSearchIsLow(): void
    {
        self::assertSame(Hazard::Low, new Scorer()->score(self::effect(EffectKind::CodeSearch)));
    }

    #[Test]
    public function webFetchIsMedium(): void
    {
        self::assertSame(Hazard::Medium, new Scorer()->score(self::effect(EffectKind::WebFetch)));
    }

    #[Test]
    public function fileWriteIsMedium(): void
    {
        self::assertSame(Hazard::Medium, new Scorer()->score(self::effect(EffectKind::FileWrite)));
    }

    #[Test]
    public function memoryWriteIsMedium(): void
    {
        self::assertSame(Hazard::Medium, new Scorer()->score(self::effect(EffectKind::MemoryWrite)));
    }

    #[Test]
    public function knowledgeWriteIsMedium(): void
    {
        self::assertSame(Hazard::Medium, new Scorer()->score(self::effect(EffectKind::KnowledgeWrite)));
    }

    #[Test]
    public function providerCallIsMedium(): void
    {
        self::assertSame(Hazard::Medium, new Scorer()->score(self::effect(EffectKind::ProviderCall)));
    }

    #[Test]
    public function shellExecIsHigh(): void
    {
        self::assertSame(Hazard::High, new Scorer()->score(self::effect(EffectKind::ShellExec)));
    }

    #[Test]
    public function customIsHigh(): void
    {
        self::assertSame(Hazard::High, new Scorer()->score(self::effect(EffectKind::Custom)));
    }

    #[Test]
    public function deterministic(): void
    {
        $scorer = new Scorer();
        $effect = self::effect(EffectKind::ShellExec);

        self::assertSame($scorer->score($effect), $scorer->score($effect));
    }

    #[Test]
    public function allNineKindsCovered(): void
    {
        $scorer = new Scorer();

        foreach (EffectKind::cases() as $kind) {
            $result = $scorer->score(self::effect($kind));
            self::assertInstanceOf(Hazard::class, $result);
        }
    }

    private static function effect(EffectKind $kind): Effect
    {
        return Effect::of(
            id: 'eff_thermopylae_01',
            kind: $kind,
            summary: 'Olympus effect: ' . $kind->value,
        );
    }
}

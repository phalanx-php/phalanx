<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\ProbeOutcome;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProbeOutcomeTest extends PhalanxTestCase
{
    #[Test]
    public function failBootCaseExists(): void
    {
        self::assertSame(ProbeOutcome::FailBoot, ProbeOutcome::FailBoot);
    }

    #[Test]
    public function featureUnavailableCaseExists(): void
    {
        self::assertSame(ProbeOutcome::FeatureUnavailable, ProbeOutcome::FeatureUnavailable);
    }

    #[Test]
    public function casesAreDistinct(): void
    {
        self::assertNotSame(ProbeOutcome::FailBoot, ProbeOutcome::FeatureUnavailable);
    }

    #[Test]
    public function allCasesReturnedByStaticCases(): void
    {
        $cases = ProbeOutcome::cases();

        self::assertCount(2, $cases);
        self::assertContains(ProbeOutcome::FailBoot, $cases);
        self::assertContains(ProbeOutcome::FeatureUnavailable, $cases);
    }
}

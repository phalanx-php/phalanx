<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit;

use Phalanx\Phalanx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    #[Test]
    public function skeletonIsReadyForV2FoundationWork(): void
    {
        self::assertSame('2.0-dev', Phalanx::VERSION);
    }
}

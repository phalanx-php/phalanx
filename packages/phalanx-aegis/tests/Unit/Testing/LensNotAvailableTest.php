<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Testing\LensNotAvailable;
use Phalanx\Tests\Fixtures\Testing\FixtureBundle;
use Phalanx\Tests\Fixtures\Testing\FixtureLens;
use PHPUnit\Framework\TestCase;

final class LensNotAvailableTest extends TestCase
{
    public function testMessageNamesLensWhenNoProvidersKnown(): void
    {
        $exception = new LensNotAvailable(FixtureLens::class);

        self::assertStringContainsString(FixtureLens::class, $exception->getMessage());
        self::assertStringContainsString('not registered on this TestApp', $exception->getMessage());
        self::assertStringContainsString('Pass a ServiceBundle that declares this lens via lens() to TestApp::boot().', $exception->getMessage());
    }

    public function testMessageListsCandidateProviders(): void
    {
        $exception = new LensNotAvailable(FixtureLens::class, [FixtureBundle::class]);

        self::assertStringContainsString(FixtureLens::class, $exception->getMessage());
        self::assertStringContainsString('Pass one of these bundles to TestApp::boot()', $exception->getMessage());
        self::assertStringContainsString(FixtureBundle::class, $exception->getMessage());
    }

    public function testIsLogicException(): void
    {
        self::assertInstanceOf(\LogicException::class, new LensNotAvailable(FixtureLens::class));
    }
}

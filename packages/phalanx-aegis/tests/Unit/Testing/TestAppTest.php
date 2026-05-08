<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Application;
use Phalanx\Testing\Fakes\FakeRegistry;
use Phalanx\Testing\LensNotAvailable;
use Phalanx\Testing\TestApp;
use Phalanx\Tests\Fixtures\Testing\ConflictingFixtureBundle;
use Phalanx\Tests\Fixtures\Testing\FixtureBundle;
use Phalanx\Tests\Fixtures\Testing\FixtureLens;
use Phalanx\Tests\Fixtures\Testing\RecordingBundle;
use Phalanx\Tests\Fixtures\Testing\RecordingLens;
use Phalanx\Tests\Fixtures\Testing\RecordingLensTarget;
use Phalanx\Tests\Fixtures\Testing\ThrowingResetBundle;
use Phalanx\Tests\Fixtures\Testing\ThrowingResetLens;
use Phalanx\Tests\Fixtures\Testing\UnattributedBundle;
use Phalanx\Tests\Fixtures\Testing\UnattributedLens;
use Phalanx\Tests\Fixtures\Testing\UnregisteredLens;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class TestAppTest extends TestCase
{
    public function testBootProducesUsableApplication(): void
    {
        $app = TestApp::boot();

        try {
            self::assertInstanceOf(Application::class, $app->application);
            self::assertInstanceOf(FakeRegistry::class, $app->fakes);
        } finally {
            $app->shutdown();
        }
    }

    public function testBootAcceptsContextAndBundles(): void
    {
        $app = TestApp::boot(['argv' => ['phpunit']], new FixtureBundle());

        try {
            self::assertInstanceOf(Application::class, $app->application);
        } finally {
            $app->shutdown();
        }
    }

    public function testLensFromTestableBundleResolvesLazily(): void
    {
        $app = TestApp::boot([], new FixtureBundle());

        try {
            $lens = $app->lens(FixtureLens::class);

            self::assertInstanceOf(FixtureLens::class, $lens);
            self::assertSame($lens, $app->lens(FixtureLens::class), 'subsequent lens() calls reuse the same instance');
        } finally {
            $app->shutdown();
        }
    }

    public function testLensThrowsWhenBundleMissing(): void
    {
        $app = TestApp::boot();

        try {
            $this->expectException(LensNotAvailable::class);
            $this->expectExceptionMessage(UnregisteredLens::class);

            $app->lens(UnregisteredLens::class);
        } finally {
            $app->shutdown();
        }
    }

    public function testLensRegistrationFailsForUnattributedLensClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UnattributedLens::class);
        $this->expectExceptionMessage('missing the #[\\Phalanx\\Testing\\Attribute\\Lens] attribute');

        TestApp::boot([], new UnattributedBundle());
    }

    public function testFakeIsResolvableViaServiceFromInsideLens(): void
    {
        $app = TestApp::boot([], new RecordingBundle());

        try {
            $fakeTarget = new RecordingLensTarget('fake');
            $app->fake(RecordingLensTarget::class, $fakeTarget);

            $lens = $app->lens(RecordingLens::class);

            self::assertSame($fakeTarget, $lens->target);
            self::assertSame('fake', $lens->target->tag);
        } finally {
            $app->shutdown();
        }
    }

    public function testServiceWithoutFakeFailsClearly(): void
    {
        $app = TestApp::boot();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('resolves only registered fakes');

            $app->service(stdClass::class);
        } finally {
            $app->shutdown();
        }
    }

    public function testResetCallsLensResetAndClearsFakes(): void
    {
        $app = TestApp::boot([], new FixtureBundle());

        try {
            $app->fake(stdClass::class, new stdClass());
            $lens = $app->lens(FixtureLens::class);
            self::assertSame(0, $lens->resetCount);

            $app->reset();

            self::assertSame(1, $lens->resetCount);
            self::assertSame('reset', $lens->tag);
            self::assertNull($app->fakes->get(stdClass::class));
        } finally {
            $app->shutdown();
        }
    }

    public function testResetContinuesPastLensThrowAndStillClearsFakes(): void
    {
        $app = TestApp::boot([], new ThrowingResetBundle());

        try {
            $app->fake(stdClass::class, new stdClass());
            $app->lens(ThrowingResetLens::class);

            try {
                $app->reset();
                self::fail('Expected reset() to surface the lens failure.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('lenses failed to reset', $e->getMessage());
                self::assertStringContainsString('reset deliberately failed', $e->getMessage());
            }

            self::assertNull(
                $app->fakes->get(stdClass::class),
                'fake registry must be cleared even when a lens reset throws',
            );
        } finally {
            $app->shutdown();
        }
    }

    public function testShutdownIsIdempotent(): void
    {
        $app = TestApp::boot();
        $app->shutdown();
        $app->shutdown();

        $this->addToAssertionCount(1);
    }

    public function testShutdownResetsAndClearsLenses(): void
    {
        $app = TestApp::boot([], new FixtureBundle());
        $lens = $app->lens(FixtureLens::class);

        $app->shutdown();

        self::assertSame(1, $lens->resetCount);

        // After shutdown, the lens cache is cleared. A fresh lens() call would
        // attempt to use the (now-disposed) Application; we only assert the
        // observable cleanup of the reset itself rather than re-resolving.
    }

    public function testNonTestableBundleDoesNotContributeLenses(): void
    {
        $bundle = new class implements \Phalanx\Service\ServiceBundle {
            public function services(\Phalanx\Service\Services $services, array $context): void
            {
            }
        };

        $app = TestApp::boot([], $bundle);

        try {
            $this->expectException(LensNotAvailable::class);
            $this->expectExceptionMessage('Pass a TestableBundle that declares this lens to TestApp::boot().');

            $app->lens(FixtureLens::class);
        } finally {
            $app->shutdown();
        }
    }

    public function testDuplicateLensAcrossBundlesIsIdempotent(): void
    {
        $app = TestApp::boot([], new FixtureBundle(), new ConflictingFixtureBundle());

        try {
            $lens = $app->lens(FixtureLens::class);

            self::assertInstanceOf(FixtureLens::class, $lens);
            self::assertSame($lens, $app->lens(FixtureLens::class));
        } finally {
            $app->shutdown();
        }
    }

    public function testLensNotAvailableMessageNamesAllProvidingBundles(): void
    {
        // Bootstrap a TestApp WITHOUT FixtureBundle so the lens stays
        // unregistered, then ask LensNotAvailable to render with both
        // potential providers passed in directly. (The TestApp itself
        // can only know about bundles passed to boot(), so we exercise
        // the message rendering separately from registration discovery.)
        $exception = new LensNotAvailable(
            FixtureLens::class,
            [FixtureBundle::class, ConflictingFixtureBundle::class],
        );

        self::assertStringContainsString(FixtureBundle::class, $exception->getMessage());
        self::assertStringContainsString(ConflictingFixtureBundle::class, $exception->getMessage());
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen\Tests\Unit;

use Phalanx\Aegis\Codegen\LensDiscovery;
use Phalanx\Aegis\Codegen\LensMetadata;
use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Tests\Fixtures\Testing\FixtureBundle;
use Phalanx\Tests\Fixtures\Testing\FixtureLens;
use Phalanx\Tests\Fixtures\Testing\RecordingBundle;
use Phalanx\Tests\Fixtures\Testing\RecordingLens;
use Phalanx\Tests\Fixtures\Testing\UnattributedBundle;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class LensDiscoveryTest extends TestCase
{
    public function testDiscoversAegisNativeLensesWithNoBundles(): void
    {
        $lenses = new LensDiscovery()->discover([]);

        $accessors = array_map(static fn(LensMetadata $m): string => $m->accessor, $lenses);

        self::assertContains('ledger', $accessors);
        self::assertContains('scope', $accessors);
        self::assertContains('runtime', $accessors);
    }

    public function testResultsAreSortedByAccessor(): void
    {
        $lenses = new LensDiscovery()->discover([FixtureBundle::class, RecordingBundle::class]);

        $accessors = array_map(static fn(LensMetadata $m): string => $m->accessor, $lenses);
        $sorted = $accessors;
        sort($sorted);

        self::assertSame($sorted, $accessors);
    }

    public function testIncludesLensesFromTestableBundles(): void
    {
        $lenses = new LensDiscovery()->discover([FixtureBundle::class, RecordingBundle::class]);

        $byClass = [];
        foreach ($lenses as $lens) {
            $byClass[$lens->lensClass] = $lens;
        }

        self::assertArrayHasKey(FixtureLens::class, $byClass);
        self::assertArrayHasKey(RecordingLens::class, $byClass);
        self::assertArrayHasKey(LedgerLens::class, $byClass);
        self::assertArrayHasKey(RuntimeLens::class, $byClass);
        self::assertArrayHasKey(ScopeLens::class, $byClass);
    }

    public function testDuplicateLensAcrossBundlesIsDeduped(): void
    {
        $lenses = new LensDiscovery()->discover([FixtureBundle::class, FixtureBundle::class]);

        $fixtureMatches = array_filter(
            $lenses,
            static fn(LensMetadata $m): bool => $m->lensClass === FixtureLens::class,
        );

        self::assertCount(1, $fixtureMatches);
    }

    public function testThrowsWhenLensClassMissingAttribute(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing the #[\\Phalanx\\Testing\\Attribute\\Lens] attribute');

        new LensDiscovery()->discover([UnattributedBundle::class]);
    }

    public function testThrowsWhenBundleClassDoesNotExtendServiceBundle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not extend ' . ServiceBundle::class);

        $method = new ReflectionMethod(LensDiscovery::class, 'discover');
        $method->invoke(new LensDiscovery(), [\stdClass::class]);
    }

    public function testFactoryClassIsResolvedFromAttribute(): void
    {
        $lenses = new LensDiscovery()->discover([FixtureBundle::class]);
        $fixture = array_find($lenses, fn($lens) => $lens->lensClass === FixtureLens::class);

        self::assertNotNull($fixture);
        self::assertSame(\Phalanx\Tests\Fixtures\Testing\FixtureLensFactory::class, $fixture->factoryClass);
    }
}

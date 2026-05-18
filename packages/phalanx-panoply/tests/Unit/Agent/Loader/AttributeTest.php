<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Agent\Loader;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Loader\Attribute;
use Phalanx\Panoply\Agent\Loader\LoaderError;
use Phalanx\Panoply\Agent\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see Attribute} loader behavior: directory walk, PSR-4 FQCN
 * derivation, #[Discovered] filtering, and LoaderError on contract violations.
 */
final class AttributeTest extends TestCase
{
    #[Test]
    public function discoveredAgentClassesAreRegistered(): void
    {
        $loader = new Attribute(self::discoveredDir(), self::discoveredPrefix());
        $registry = $loader->load();

        self::assertInstanceOf(Registry::class, $registry);
        // HoplitesAgent and PhalanxAgent carry #[Discovered]; NonAgentClass does not.
        self::assertSame(2, $registry->all()->count());
        self::assertTrue($registry->has('hoplites'));
        self::assertTrue($registry->has('phalanx'));
    }

    #[Test]
    public function nonAttributedClassIsSkipped(): void
    {
        $loader = new Attribute(self::discoveredDir(), self::discoveredPrefix());
        $registry = $loader->load();

        // NonAgentClass has no #[Discovered] and must not appear.
        $ids = array_map(
            static fn (Agent $a): string => $a->id,
            $registry->all()->toArray(),
        );
        self::assertNotContains('non_agent', $ids);
    }

    #[Test]
    public function discoveredClassNotImplementingAgentThrowsLoaderError(): void
    {
        // bad_discovered/ contains BadDiscoveredClass which has #[Discovered]
        // but does not implement Agent.
        $loader = new Attribute(self::badDir(), self::badPrefix());

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('does not implement');

        $loader->load();
    }

    #[Test]
    public function nonExistentDirectoryYieldsEmptyRegistry(): void
    {
        $loader = new Attribute('/does/not/exist', 'App\\Agents');
        $registry = $loader->load();

        self::assertSame(0, $registry->all()->count());
    }

    #[Test]
    public function discoveredClassWithRequiredConstructorThrowsLoaderError(): void
    {
        // RequiredArgAgent carries #[Discovered], implements Agent, but has a
        // required constructor arg. The loader must throw nonTrivialConstructor.
        $loader = new Attribute(self::requiredConstructorDir(), self::requiredConstructorPrefix());

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('non-trivial constructor');

        $loader->load();
    }

    #[Test]
    public function loadIsIdempotent(): void
    {
        $loader = new Attribute(self::discoveredDir(), self::discoveredPrefix());

        $r1 = $loader->load();
        $r2 = $loader->load();

        self::assertSame(2, $r1->all()->count());
        self::assertSame(2, $r2->all()->count());
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Agent';
    }

    private static function discoveredDir(): string
    {
        return self::fixtureRoot() . '/discovered';
    }

    private static function discoveredPrefix(): string
    {
        return 'Phalanx\\Panoply\\Tests\\Fixtures\\Agent\\Discovered';
    }

    private static function badDir(): string
    {
        return self::fixtureRoot() . '/BadDiscovered';
    }

    private static function badPrefix(): string
    {
        return 'Phalanx\\Panoply\\Tests\\Fixtures\\Agent\\BadDiscovered';
    }

    private static function requiredConstructorDir(): string
    {
        return self::fixtureRoot() . '/RequiredConstructor';
    }

    private static function requiredConstructorPrefix(): string
    {
        return 'Phalanx\\Panoply\\Tests\\Fixtures\\Agent\\RequiredConstructor';
    }
}

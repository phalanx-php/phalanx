<?php

declare(strict_types=1);

namespace Phalanx\Themis\Tests\Unit;

use LogicException;
use Phalanx\Themis\Config;
use Phalanx\Themis\ConfigCatalog;
use Phalanx\Themis\Env;
use Phalanx\Themis\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigCatalogTest extends TestCase
{
    #[Test]
    public function singleRootWithFlatEntries(): void
    {
        $catalog = ConfigCatalog::of(CatalogFlatConfig::class);
        $tree = $catalog->tree();

        self::assertCount(1, $tree);
        self::assertSame(CatalogFlatConfig::class, $tree[0]->type);
        self::assertSame('CatalogFlatConfig', $tree[0]->path);
        self::assertCount(2, $tree[0]->entries);
        self::assertSame('APOLLO_HOST', $tree[0]->entries[0]->envKey);
        self::assertSame('APOLLO_PORT', $tree[0]->entries[1]->envKey);
        self::assertCount(0, $tree[0]->children);
    }

    #[Test]
    public function nestedConfigDiscovery(): void
    {
        $catalog = ConfigCatalog::of(CatalogParentConfig::class);
        $tree = $catalog->tree();

        self::assertCount(1, $tree);
        $root = $tree[0];
        self::assertSame('CatalogParentConfig', $root->path);
        self::assertCount(1, $root->children);

        $child = $root->children[0];
        self::assertSame(CatalogFlatConfig::class, $child->type);
        self::assertSame('CatalogParentConfig.inner', $child->path);
        self::assertCount(2, $child->entries);
        self::assertCount(0, $child->children);
    }

    #[Test]
    public function cycleDetectionThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cycle detected/');

        ConfigCatalog::of(CatalogCyclicConfig::class)->tree();
    }

    #[Test]
    public function sameClassAtDifferentPathsIsAllowed(): void
    {
        $catalog = ConfigCatalog::of(CatalogDuplicatePathConfig::class);
        $tree = $catalog->tree();

        self::assertCount(1, $tree);
        $root = $tree[0];
        self::assertCount(2, $root->children);
        self::assertSame(CatalogFlatConfig::class, $root->children[0]->type);
        self::assertSame(CatalogFlatConfig::class, $root->children[1]->type);
        self::assertSame('CatalogDuplicatePathConfig.first', $root->children[0]->path);
        self::assertSame('CatalogDuplicatePathConfig.second', $root->children[1]->path);
    }

    #[Test]
    public function pathTrackingProducesCorrectDotSeparatedPaths(): void
    {
        $catalog = ConfigCatalog::of(CatalogDeepConfig::class);
        $tree = $catalog->tree();

        $root = $tree[0];
        self::assertSame('CatalogDeepConfig', $root->path);

        $mid = $root->children[0];
        self::assertSame('CatalogDeepConfig.parent', $mid->path);

        $leaf = $mid->children[0];
        self::assertSame('CatalogDeepConfig.parent.inner', $leaf->path);
    }

    #[Test]
    public function emptyConfigProducesNodeWithNoEntriesOrChildren(): void
    {
        $catalog = ConfigCatalog::of(CatalogEmptyConfig::class);
        $tree = $catalog->tree();

        self::assertCount(1, $tree);
        self::assertCount(0, $tree[0]->entries);
        self::assertCount(0, $tree[0]->children);
    }

    #[Test]
    public function multipleRootsProduceMultipleTopLevelNodes(): void
    {
        $catalog = ConfigCatalog::of(CatalogFlatConfig::class, CatalogEmptyConfig::class);
        $tree = $catalog->tree();

        self::assertCount(2, $tree);
        self::assertSame(CatalogFlatConfig::class, $tree[0]->type);
        self::assertSame(CatalogEmptyConfig::class, $tree[1]->type);
    }

    #[Test]
    public function classesDeduplicatesSharedNestedTypes(): void
    {
        $catalog = ConfigCatalog::of(CatalogDuplicatePathConfig::class);
        $classes = $catalog->classes();

        self::assertContains(CatalogDuplicatePathConfig::class, $classes);
        self::assertContains(CatalogFlatConfig::class, $classes);
        self::assertCount(2, $classes);
    }

    #[Test]
    public function classesIncludesAllRootsAndNestedTypes(): void
    {
        $catalog = ConfigCatalog::of(CatalogParentConfig::class);
        $classes = $catalog->classes();

        self::assertContains(CatalogParentConfig::class, $classes);
        self::assertContains(CatalogFlatConfig::class, $classes);
        self::assertCount(2, $classes);
    }

    #[Test]
    public function definitionsReturnsFlatListOfAllDefinitions(): void
    {
        $catalog = ConfigCatalog::of(CatalogParentConfig::class);
        $definitions = $catalog->definitions();

        $types = array_map(static fn($d): string => $d->type, $definitions);
        self::assertContains(CatalogParentConfig::class, $types);
        self::assertContains(CatalogFlatConfig::class, $types);
    }
}

final class CatalogFlatConfig implements Config
{
    public bool $configured {
        get => $this->host !== '';
    }

    public function __construct(
        #[Env(key: 'APOLLO_HOST', description: 'Apollo host')]
        public string $host = 'localhost',
        #[Env(key: 'APOLLO_PORT', description: 'Apollo port')]
        public int $port = 8080,
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class CatalogEmptyConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class CatalogParentConfig implements Config
{
    public bool $configured {
        get => $this->inner->configured;
    }

    public function __construct(
        public CatalogFlatConfig $inner = new CatalogFlatConfig(),
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return $this->inner->validate($context);
    }
}

final class CatalogDuplicatePathConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public CatalogFlatConfig $first = new CatalogFlatConfig(),
        public CatalogFlatConfig $second = new CatalogFlatConfig(),
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class CatalogDeepConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public CatalogParentConfig $parent = new CatalogParentConfig(),
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class CatalogCyclicConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public CatalogCyclicChild $child = new CatalogCyclicChild(),
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

final class CatalogCyclicChild implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        public CatalogCyclicConfig $parent = new CatalogCyclicConfig(),
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        return [];
    }
}

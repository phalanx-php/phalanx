<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Provider\Config;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\DuplicateModelAlias;
use Phalanx\Panoply\Provider\Registry;
use Phalanx\Panoply\Provider\Resolution;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins spec acceptance gate #14.
 */
final class RegistryTest extends TestCase
{
    #[Test]
    public function emptyRegistryReturnsNullOnGet(): void
    {
        self::assertNull(Registry::empty()->get('anthropic'));
    }

    #[Test]
    public function emptyRegistryHasNothingAndAllIsEmpty(): void
    {
        $registry = Registry::empty();

        self::assertFalse($registry->has('anything'));
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function withIsImmutable(): void
    {
        $a = Registry::empty();
        $b = $a->with(self::config('olympus'));

        self::assertNotSame($a, $b);
        self::assertFalse($a->has('olympus'));
        self::assertTrue($b->has('olympus'));
    }

    #[Test]
    public function getReturnsConfigById(): void
    {
        $config = self::config('sparta');
        $registry = Registry::empty()->with($config);

        self::assertSame($config, $registry->get('sparta'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownId(): void
    {
        $registry = Registry::empty()->with(self::config('sparta'));

        self::assertFalse($registry->has('athens'));
    }

    #[Test]
    public function allReturnsAllConfigs(): void
    {
        $registry = Registry::empty()
            ->with(self::config('sparta'))
            ->with(self::config('athens'));

        self::assertCount(2, $registry->all());
        self::assertArrayHasKey('sparta', $registry->all());
        self::assertArrayHasKey('athens', $registry->all());
    }

    #[Test]
    public function byModelAliasResolvesExactAlias(): void
    {
        $registry = Registry::empty()->with(self::configWithModel(
            'olympus',
            'apollo-v1',
            'apollo-model-id',
            ['apollo', 'light', 'sun'],
        ));

        $result = $registry->byModelAlias('apollo');

        self::assertNotNull($result);
        self::assertInstanceOf(Resolution::class, $result);
        self::assertSame('olympus', $result->config->id);
        self::assertSame('apollo-v1', $result->model->name);
    }

    #[Test]
    public function byModelAliasResolvesByModelName(): void
    {
        $registry = Registry::empty()->with(self::configWithModel(
            'olympus',
            'opus',
            'opus-model-id',
            [],
        ));

        $result = $registry->byModelAlias('opus');

        self::assertNotNull($result);
        self::assertInstanceOf(Resolution::class, $result);
        self::assertSame('opus', $result->model->name);
    }

    #[Test]
    public function byModelAliasReturnsNullForNoMatch(): void
    {
        $registry = Registry::empty()->with(self::configWithModel(
            'olympus',
            'apollo-v1',
            'apollo-model-id',
            ['apollo'],
        ));

        self::assertNull($registry->byModelAlias('zeus'));
    }

    #[Test]
    public function byModelAliasSearchesAllConfigs(): void
    {
        $registry = Registry::empty()
            ->with(self::configWithModel('olympus', 'apollo-v1', 'apollo-model-id', ['apollo']))
            ->with(self::configWithModel('sparta', 'leonidas-v1', 'leonidas-model-id', ['leo', 'king']));

        $result = $registry->byModelAlias('leo');

        self::assertNotNull($result);
        self::assertInstanceOf(Resolution::class, $result);
        self::assertSame('sparta', $result->config->id);
    }

    #[Test]
    public function withRejectsDuplicateModelAlias(): void
    {
        $sparta = self::configWithModel('sparta', 'leonidas-v1', 'leonidas-model-id', ['leonidas', 'king']);
        $athens = self::configWithModel('athens', 'leonidas-copy', 'leonidas-copy-id', ['leonidas']);

        $registry = Registry::empty()->with($sparta);

        $this->expectException(DuplicateModelAlias::class);
        $registry->with($athens);
    }

    #[Test]
    public function withRejectsDuplicateModelNameAsAlias(): void
    {
        $sparta = self::configWithModel('sparta', 'leonidas-v1', 'leonidas-model-id', []);
        // athens has a model whose name collides with sparta's model name
        $athens = self::configWithModel('athens', 'leonidas-v1', 'athens-leonidas-id', []);

        $registry = Registry::empty()->with($sparta);

        $this->expectException(DuplicateModelAlias::class);
        $registry->with($athens);
    }

    private static function config(string $id): Config
    {
        return Config::of(
            id: $id,
            displayName: ucfirst($id) . ' Provider',
            models: [],
            capabilities: Capabilities::of(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
        );
    }

    /**
     * @param list<string> $aliases
     */
    private static function configWithModel(
        string $providerId,
        string $modelName,
        string $modelId,
        array $aliases,
    ): Config {
        $model = Model::of(
            name: $modelName,
            modelId: $modelId,
            aliases: $aliases,
            capabilities: Capabilities::of(Capability::Reasoning),
        );

        return Config::of(
            id: $providerId,
            displayName: ucfirst($providerId) . ' Provider',
            models: [$model],
            capabilities: Capabilities::of(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}

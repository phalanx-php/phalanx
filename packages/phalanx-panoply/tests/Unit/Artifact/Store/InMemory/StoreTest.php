<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Artifact\Store\InMemory;

use Phalanx\Panoply\Artifact;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Artifact\Store\InMemory\Store;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    #[Test]
    public function saveAndByIdRoundtrip(): void
    {
        $store = new Store();
        $artifact = self::artifact('art_01', 'leonidas', 'act_sparta');

        $store->save($artifact);

        self::assertSame($artifact, $store->byId('art_01'));
    }

    #[Test]
    public function byIdReturnsNullForMissingId(): void
    {
        self::assertNull(new Store()->byId('missing'));
    }

    #[Test]
    public function upsertOverwritesPreviousRecord(): void
    {
        $store = new Store();
        $first = self::artifact('art_01', 'leonidas', 'act_sparta');
        $updated = $first->withContent('Hoplite formation updated');

        $store->save($first);
        $store->save($updated);

        $retrieved = $store->byId('art_01');
        self::assertNotNull($retrieved);
        self::assertSame('Hoplite formation updated', $retrieved->content);
    }

    #[Test]
    public function byActivityFiltersCorrectly(): void
    {
        $store = new Store();
        $store->save(self::artifact('art_01', 'leonidas', 'act_marathon'));
        $store->save(self::artifact('art_02', 'odysseus', 'act_marathon'));
        $store->save(self::artifact('art_03', 'achilles', 'act_thermopylae'));

        $collection = $store->byActivity('act_marathon')->toArray();

        self::assertCount(2, $collection);
        $ids = array_map(static fn (Artifact $a): string => $a->id, $collection);
        self::assertContains('art_01', $ids);
        self::assertContains('art_02', $ids);
        self::assertNotContains('art_03', $ids);
    }

    #[Test]
    public function byActivityReturnsEmptyCollectionForUnknownActivity(): void
    {
        $store = new Store();
        $store->save(self::artifact('art_01', 'leonidas', 'act_sparta'));

        $collection = $store->byActivity('act_unknown')->toArray();

        self::assertSame([], $collection);
    }

    #[Test]
    public function allReturnsEveryArtifact(): void
    {
        $store = new Store();
        $store->save(self::artifact('art_01', 'leonidas', 'act_a'));
        $store->save(self::artifact('art_02', 'odysseus', 'act_b'));
        $store->save(self::artifact('art_03', 'achilles', 'act_c'));

        $all = $store->all()->toArray();

        self::assertCount(3, $all);
    }

    #[Test]
    public function allStartsEmpty(): void
    {
        $store = new Store();

        self::assertSame([], $store->all()->toArray());
    }

    #[Test]
    public function byActivityReturnsCollection(): void
    {
        $store = new Store();

        self::assertInstanceOf(
            \Phalanx\Panoply\Artifact\Collection::class,
            $store->byActivity('any'),
        );
    }

    #[Test]
    public function allReturnsCollection(): void
    {
        $store = new Store();

        self::assertInstanceOf(
            \Phalanx\Panoply\Artifact\Collection::class,
            $store->all(),
        );
    }

    private static function artifact(string $id, string $agentId, string $activityId): Artifact
    {
        return Artifact::draft(
            id: $id,
            kind: ArtifactKind::Thesis,
            agentId: $agentId,
            activityId: $activityId,
            createdAt: new \DateTimeImmutable('2026-05-17T00:00:00Z'),
        );
    }
}

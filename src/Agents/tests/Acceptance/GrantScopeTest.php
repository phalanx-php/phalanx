<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Acceptance;

use Phalanx\Agents\Grant\MemoryGrantStore;
use Phalanx\Agents\Testing\ScopeStub;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrantScopeTest extends TestCase
{
    #[Test]
    public function sessionGrantRemainsAvailableAfterFirstLookup(): void
    {
        $scope = new ScopeStub();
        $grantStore = new MemoryGrantStore();
        $grant = Grant::of(
            id: 'grant_session',
            subject: 'agent_session',
            allowedEffects: [Kind::FileRead],
            scope: 'session',
            hazardCeiling: Hazard::High,
        );

        $grantStore->remember($scope, $grant);

        $first = $grantStore->find($scope, 'agent_session', Kind::FileRead);
        $second = $grantStore->find($scope, 'agent_session', Kind::FileRead);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('grant_session', $first->id);
        self::assertSame('grant_session', $second->id);
    }

    #[Test]
    public function onceGrantIsConsumedAndUnavailableAfterRevoke(): void
    {
        $scope = new ScopeStub();
        $grantStore = new MemoryGrantStore();
        $grant = Grant::of(
            id: 'grant_once',
            subject: 'agent_once',
            allowedEffects: [Kind::FileWrite],
            scope: 'once',
            hazardCeiling: Hazard::Critical,
        );

        $grantStore->remember($scope, $grant);

        $found = $grantStore->find($scope, 'agent_once', Kind::FileWrite);
        self::assertNotNull($found);

        $grantStore->consume($scope, $grant);

        $afterConsume = $grantStore->find($scope, 'agent_once', Kind::FileWrite);
        self::assertNull($afterConsume, 'Grant must not be found after consume');
    }

    #[Test]
    public function alwaysGrantPersistsAcrossMultipleFinds(): void
    {
        $scope = new ScopeStub();
        $grantStore = new MemoryGrantStore();
        $grant = Grant::of(
            id: 'grant_always',
            subject: 'agent_always',
            allowedEffects: [Kind::WebFetch],
            scope: 'always',
            hazardCeiling: Hazard::Medium,
        );

        $grantStore->remember($scope, $grant);

        for ($i = 0; $i < 5; $i++) {
            $found = $grantStore->find($scope, 'agent_always', Kind::WebFetch);
            self::assertNotNull($found, "Grant must remain available on lookup {$i}");
        }
    }

    #[Test]
    public function expiredGrantIsNotReturnedByFind(): void
    {
        $scope = new ScopeStub();
        $grantStore = new MemoryGrantStore();
        $grant = Grant::of(
            id: 'grant_expired',
            subject: 'agent_expired',
            allowedEffects: [Kind::FileRead],
            scope: 'session',
            hazardCeiling: Hazard::Low,
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );

        $grantStore->remember($scope, $grant);

        $found = $grantStore->find($scope, 'agent_expired', Kind::FileRead);
        self::assertNull($found, 'Expired grant must not be returned');
    }
}

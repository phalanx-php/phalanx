<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Harness\Message;

use Phalanx\Theatron\Harness\Message\Address;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    #[Test]
    public function addressFactoriesProduceStableIdentityAndRole(): void
    {
        self::assertSame('user', Address::user()->identity);
        self::assertSame('user', Address::user()->role);
        self::assertSame('agent:assistant', Address::agent('assistant')->identity);
        self::assertSame('service:daemon8', Address::service('daemon8')->identity);
        self::assertSame('system', Address::system()->identity);
    }

    #[Test]
    public function addressNormalizesIdentityAndRoleWhitespace(): void
    {
        $address = Address::named('  participant:reviewer  ', ' reviewer ');

        self::assertSame('participant:reviewer', $address->identity);
        self::assertSame('reviewer', $address->role);
    }

    #[Test]
    public function addressCanonicalFormIsStable(): void
    {
        self::assertSame(
            ['identity' => 'agent:assistant', 'role' => 'agent'],
            Address::agent('assistant')->toCanonical(),
        );
    }

    #[Test]
    public function sameAddressComparesEqualByValue(): void
    {
        self::assertTrue(Address::agent('assistant')->equals(Address::agent('assistant')));
        self::assertFalse(Address::agent('assistant')->equals(Address::agent('reviewer')));
    }

    #[Test]
    public function addressRejectsEmptyIdentityParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agent id');

        Address::agent('');
    }
}

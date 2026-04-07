<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit\Validator\Param;

use Phalanx\Http\Validator\Param\UuidV4;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UuidV4Test extends TestCase
{
    #[Test]
    public function validate_always_returns_null(): void
    {
        // Pattern does all enforcement at FastRoute level; validate is a no-op.
        $v = new UuidV4();
        $this->assertNull($v->validate('id', 'anything'));
    }

    #[Test]
    public function provides_uuid_v4_pattern(): void
    {
        $v = new UuidV4();
        $pattern = $v->toPattern();
        $this->assertNotNull($pattern);

        // Verify the pattern matches a valid UUID v4
        $valid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertMatchesRegularExpression('#^' . $pattern . '$#', $valid);
    }

    #[Test]
    public function pattern_rejects_uuid_v1(): void
    {
        $v = new UuidV4();
        $pattern = $v->toPattern();
        $this->assertNotNull($pattern);

        // UUID v1 has version nibble '1'
        $v1 = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $this->assertDoesNotMatchRegularExpression('#^' . $pattern . '$#', $v1);
    }
}

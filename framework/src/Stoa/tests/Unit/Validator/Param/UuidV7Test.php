<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Validator\Param;

use Phalanx\Stoa\Validator\Param\UuidV7;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UuidV7Test extends TestCase
{
    #[Test]
    public function validate_always_returns_null(): void
    {
        $v = new UuidV7();
        $this->assertNull($v->validate('id', 'anything'));
    }

    #[Test]
    public function provides_uuid_v7_pattern(): void
    {
        $v = new UuidV7();
        $pattern = $v->toPattern();
        $this->assertNotNull($pattern);

        // Verify the pattern matches a valid UUID v7 (version nibble is 7)
        $valid = '018f4891-e29b-7d4a-a716-446655440000';
        $this->assertMatchesRegularExpression('#^' . $pattern . '$#', $valid);
    }

    #[Test]
    public function pattern_rejects_uuid_v4(): void
    {
        $v = new UuidV7();
        $pattern = $v->toPattern();
        $this->assertNotNull($pattern);

        // UUID v4 has version nibble '4'
        $v4 = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertDoesNotMatchRegularExpression('#^' . $pattern . '$#', $v4);
    }
}

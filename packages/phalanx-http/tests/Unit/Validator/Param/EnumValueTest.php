<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit\Validator\Param;

use Phalanx\Http\Validator\Param\EnumValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class EnumValueTest extends TestCase
{
    #[Test]
    public function returns_null_for_valid_enum_value(): void
    {
        $v = new EnumValue(TestStatus::class);
        $this->assertNull($v->validate('status', 'active'));
        $this->assertNull($v->validate('status', 'inactive'));
    }

    #[Test]
    public function returns_error_for_invalid_enum_value(): void
    {
        $v = new EnumValue(TestStatus::class);
        $error = $v->validate('status', 'pending');
        $this->assertNotNull($error);
        $this->assertStringContainsString('status', $error);
        $this->assertStringContainsString('active', $error);
    }

    #[Test]
    public function provides_no_pattern(): void
    {
        $v = new EnumValue(TestStatus::class);
        $this->assertNull($v->toPattern());
    }
}

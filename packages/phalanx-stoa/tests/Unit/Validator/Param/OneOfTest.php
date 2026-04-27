<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Validator\Param;

use Phalanx\Stoa\Validator\Param\OneOf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OneOfTest extends TestCase
{
    #[Test]
    public function returns_null_for_allowed_value(): void
    {
        $v = new OneOf('asc', 'desc');
        $this->assertNull($v->validate('sort', 'asc'));
        $this->assertNull($v->validate('sort', 'desc'));
    }

    #[Test]
    public function returns_error_for_disallowed_value(): void
    {
        $v = new OneOf('asc', 'desc');
        $error = $v->validate('sort', 'random');
        $this->assertNotNull($error);
        $this->assertStringContainsString('sort', $error);
        $this->assertStringContainsString('asc', $error);
        $this->assertStringContainsString('desc', $error);
    }

    #[Test]
    public function provides_no_pattern(): void
    {
        $v = new OneOf('a', 'b');
        $this->assertNull($v->toPattern());
    }

    #[Test]
    public function is_case_sensitive(): void
    {
        $v = new OneOf('asc', 'desc');
        $error = $v->validate('sort', 'ASC');
        $this->assertNotNull($error);
    }
}

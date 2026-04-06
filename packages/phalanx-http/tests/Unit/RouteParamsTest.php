<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit;

use Phalanx\Http\RouteParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteParamsTest extends TestCase
{
    #[Test]
    public function get_returns_value(): void
    {
        $params = new RouteParams(['id' => '42', 'slug' => 'hello']);

        self::assertSame('42', $params->get('id'));
        self::assertSame('hello', $params->get('slug'));
        self::assertNull($params->get('missing'));
        self::assertSame('default', $params->get('missing', 'default'));
    }

    #[Test]
    public function int_casts_value(): void
    {
        $params = new RouteParams(['id' => '42']);

        self::assertSame(42, $params->int('id'));
        self::assertNull($params->int('missing'));
        self::assertSame(0, $params->int('missing', 0));
    }

    #[Test]
    public function required_throws_on_missing(): void
    {
        $params = new RouteParams([]);

        $this->expectException(\RuntimeException::class);
        $params->required('id');
    }

    #[Test]
    public function has(): void
    {
        $params = new RouteParams(['id' => '1']);

        self::assertTrue($params->has('id'));
        self::assertFalse($params->has('missing'));
    }
}

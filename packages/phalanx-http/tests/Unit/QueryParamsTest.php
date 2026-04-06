<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit;

use Phalanx\Http\QueryParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryParamsTest extends TestCase
{
    #[Test]
    public function get_returns_value(): void
    {
        $params = new QueryParams(['page' => '2', 'sort' => 'name']);

        self::assertSame('2', $params->get('page'));
        self::assertNull($params->get('missing'));
    }

    #[Test]
    public function int_casts(): void
    {
        $params = new QueryParams(['page' => '3']);

        self::assertSame(3, $params->int('page'));
        self::assertNull($params->int('missing'));
        self::assertSame(1, $params->int('missing', 1));
    }

    #[Test]
    public function bool_parses(): void
    {
        $params = new QueryParams([
            'active' => 'true',
            'deleted' => '0',
            'featured' => '1',
        ]);

        self::assertTrue($params->bool('active'));
        self::assertFalse($params->bool('deleted'));
        self::assertTrue($params->bool('featured'));
        self::assertFalse($params->bool('missing'));
        self::assertTrue($params->bool('missing', true));
    }

    #[Test]
    public function has(): void
    {
        $params = new QueryParams(['page' => '1']);

        self::assertTrue($params->has('page'));
        self::assertFalse($params->has('missing'));
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Concurrency;

use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettlementTest extends TestCase
{
    // Settlement Monad Tests

    #[Test]
    public function ok_settlement_properties(): void
    {
        $settlement = Settlement::ok('success');

        $this->assertTrue($settlement->isOk);
        $this->assertSame('success', $settlement->value);
        $this->assertNull($settlement->error);
    }

    #[Test]
    public function err_settlement_properties(): void
    {
        $exception = new RuntimeException('failed');
        $settlement = Settlement::err($exception);

        $this->assertFalse($settlement->isOk);
        $this->assertNull($settlement->value);
        $this->assertSame($exception, $settlement->error);
    }

    #[Test]
    public function unwrap_on_ok_returns_value(): void
    {
        $settlement = Settlement::ok(['data' => 123]);

        $this->assertSame(['data' => 123], $settlement->unwrap());
    }

    #[Test]
    public function unwrap_on_err_throws_original_exception(): void
    {
        $original = new RuntimeException('original error');
        $settlement = Settlement::err($original);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original error');

        $settlement->unwrap();
    }

    #[Test]
    public function unwrap_or_returns_value_on_ok(): void
    {
        $settlement = Settlement::ok('actual');

        $this->assertSame('actual', $settlement->unwrapOr('default'));
    }

    #[Test]
    public function unwrap_or_returns_default_on_err(): void
    {
        $settlement = Settlement::err(new RuntimeException());

        $this->assertSame('default', $settlement->unwrapOr('default'));
    }

    #[Test]
    public function error_method_returns_throwable(): void
    {
        $exception = new RuntimeException('test');
        $errSettlement = Settlement::err($exception);
        $okSettlement = Settlement::ok('value');

        $this->assertSame($exception, $errSettlement->error());
        $this->assertNull($okSettlement->error());
    }

    // SettlementBag Tests

    #[Test]
    public function computed_properties_all_ok(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok(1),
            'b' => Settlement::ok(2),
            'c' => Settlement::ok(3),
        ]);

        $this->assertTrue($bag->allOk);
        $this->assertTrue($bag->anyOk);
        $this->assertFalse($bag->allErr);
        $this->assertFalse($bag->anyErr);
        $this->assertEquals(['a', 'b', 'c'], $bag->okKeys);
        $this->assertEquals([], $bag->errKeys);
    }

    #[Test]
    public function computed_properties_all_err(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::err(new RuntimeException('1')),
            'b' => Settlement::err(new RuntimeException('2')),
        ]);

        $this->assertFalse($bag->allOk);
        $this->assertFalse($bag->anyOk);
        $this->assertTrue($bag->allErr);
        $this->assertTrue($bag->anyErr);
        $this->assertEquals([], $bag->okKeys);
        $this->assertEquals(['a', 'b'], $bag->errKeys);
    }

    #[Test]
    public function computed_properties_mixed(): void
    {
        $bag = new SettlementBag([
            'success' => Settlement::ok('value'),
            'failure' => Settlement::err(new RuntimeException('error')),
        ]);

        $this->assertFalse($bag->allOk);
        $this->assertTrue($bag->anyOk);
        $this->assertFalse($bag->allErr);
        $this->assertTrue($bag->anyErr);
        $this->assertEquals(['success'], $bag->okKeys);
        $this->assertEquals(['failure'], $bag->errKeys);
    }

    #[Test]
    public function values_and_errors_properties(): void
    {
        $exception = new RuntimeException('error');
        $bag = new SettlementBag([
            'a' => Settlement::ok(1),
            'b' => Settlement::err($exception),
            'c' => Settlement::ok(3),
        ]);

        $this->assertEquals(['a' => 1, 'c' => 3], $bag->values);
        $this->assertEquals(['b' => $exception], $bag->errors);
    }

    #[Test]
    public function get_with_default(): void
    {
        $bag = new SettlementBag([
            'existing' => Settlement::ok('found'),
            'failed' => Settlement::err(new RuntimeException('error')),
        ]);

        $this->assertSame('found', $bag->get('existing'));
        $this->assertSame('default', $bag->get('missing', 'default'));
        $this->assertSame('fallback', $bag->get('failed', 'fallback'));
    }

    #[Test]
    public function extract_with_defaults(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok(1),
            'b' => Settlement::err(new RuntimeException()),
        ]);

        $extracted = $bag->extract([
            'a' => 0,
            'b' => 0,
            'c' => 99,
        ]);

        $this->assertEquals([
            'a' => 1,
            'b' => 0,
            'c' => 99,
        ], $extracted);
    }

    #[Test]
    public function unwrap_all_on_all_ok(): void
    {
        $bag = new SettlementBag([
            'x' => Settlement::ok(10),
            'y' => Settlement::ok(20),
        ]);

        $this->assertEquals(['x' => 10, 'y' => 20], $bag->unwrapAll());
    }

    #[Test]
    public function unwrap_all_on_any_err_throws_first(): void
    {
        $first = new RuntimeException('first error');
        $bag = new SettlementBag([
            'a' => Settlement::err($first),
            'b' => Settlement::ok('value'),
            'c' => Settlement::err(new RuntimeException('second')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first error');

        $bag->unwrapAll();
    }

    #[Test]
    public function partition_returns_tuple(): void
    {
        $error = new RuntimeException('error');
        $bag = new SettlementBag([
            'ok1' => Settlement::ok(1),
            'err1' => Settlement::err($error),
            'ok2' => Settlement::ok(2),
        ]);

        [$values, $errors] = $bag->partition();

        $this->assertEquals(['ok1' => 1, 'ok2' => 2], $values);
        $this->assertEquals(['err1' => $error], $errors);
    }

    #[Test]
    public function map_ok_transforms_successes_only(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok(5),
            'b' => Settlement::err(new RuntimeException()),
            'c' => Settlement::ok(10),
        ]);

        $mapped = $bag->mapOk(fn($value) => $value * 2);

        $this->assertEquals(['a' => 10, 'c' => 20], $mapped);
    }

    #[Test]
    public function settlement_method_returns_raw(): void
    {
        $okSettlement = Settlement::ok('value');
        $errSettlement = Settlement::err(new RuntimeException());

        $bag = new SettlementBag([
            'ok' => $okSettlement,
            'err' => $errSettlement,
        ]);

        $this->assertSame($okSettlement, $bag->settlement('ok'));
        $this->assertSame($errSettlement, $bag->settlement('err'));
        $this->assertNull($bag->settlement('missing'));
    }

    #[Test]
    public function is_ok_and_is_err_methods(): void
    {
        $bag = new SettlementBag([
            'success' => Settlement::ok('value'),
            'failure' => Settlement::err(new RuntimeException()),
        ]);

        $this->assertTrue($bag->isOk('success'));
        $this->assertFalse($bag->isOk('failure'));
        $this->assertFalse($bag->isOk('missing'));

        $this->assertFalse($bag->isErr('success'));
        $this->assertTrue($bag->isErr('failure'));
        $this->assertFalse($bag->isErr('missing'));
    }

    #[Test]
    public function array_access_offset_exists(): void
    {
        $bag = new SettlementBag([
            'key' => Settlement::ok('value'),
        ]);

        $this->assertTrue(isset($bag['key']));
        $this->assertFalse(isset($bag['missing']));
    }

    #[Test]
    public function array_access_offset_get(): void
    {
        $settlement = Settlement::ok('value');
        $bag = new SettlementBag(['key' => $settlement]);

        $this->assertSame($settlement, $bag['key']);
        $this->assertNull($bag['missing']);
    }

    #[Test]
    public function array_access_offset_set_throws(): void
    {
        $bag = new SettlementBag([]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SettlementBag is immutable');

        $bag['key'] = Settlement::ok('value');
    }

    #[Test]
    public function array_access_offset_unset_throws(): void
    {
        $bag = new SettlementBag([
            'key' => Settlement::ok('value'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SettlementBag is immutable');

        unset($bag['key']);
    }

    #[Test]
    public function countable_interface(): void
    {
        $bag = new SettlementBag([
            'a' => Settlement::ok(1),
            'b' => Settlement::ok(2),
            'c' => Settlement::err(new RuntimeException()),
        ]);

        $this->assertCount(3, $bag);
    }

    #[Test]
    public function iterable_interface(): void
    {
        $settlements = [
            'a' => Settlement::ok(1),
            'b' => Settlement::ok(2),
        ];
        $bag = new SettlementBag($settlements);

        $iterated = [];
        foreach ($bag as $key => $settlement) {
            $iterated[$key] = $settlement;
        }

        $this->assertEquals($settlements, $iterated);
    }

    #[Test]
    public function settlements_property_is_readonly(): void
    {
        $settlements = ['key' => Settlement::ok('value')];
        $bag = new SettlementBag($settlements);

        $this->assertEquals($settlements, $bag->settlements);
    }

    #[Test]
    public function handles_integer_keys(): void
    {
        $bag = new SettlementBag([
            0 => Settlement::ok('first'),
            1 => Settlement::err(new RuntimeException()),
            2 => Settlement::ok('third'),
        ]);

        $this->assertTrue($bag->isOk(0));
        $this->assertTrue($bag->isErr(1));
        $this->assertSame('first', $bag->get(0));
        $this->assertEquals([0, 2], $bag->okKeys);
        $this->assertEquals([1], $bag->errKeys);
    }
}

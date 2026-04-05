<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Concurrency;

use Phalanx\Concurrency\SingleflightGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use RuntimeException;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;

final class SingleflightGroupTest extends TestCase
{
    #[Test]
    public function single_caller_executes_and_returns_result(): void
    {
        $group = new SingleflightGroup();
        $calls = 0;

        $result = $group->do('key', static function () use (&$calls) {
            $calls++;
            return 'value';
        });

        $this->assertSame('value', $result);
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function concurrent_callers_share_single_execution(): void
    {
        $group = new SingleflightGroup();
        $executions = 0;

        $task = static function () use ($group, &$executions) {
            return $group->do('shared-key', static function () use (&$executions) {
                $executions++;
                delay(0.05);
                return 'shared-result';
            });
        };

        $promises = [
            async($task)(),
            async($task)(),
            async($task)(),
        ];

        $results = await(all($promises));

        $this->assertSame(1, $executions);
        $this->assertSame(['shared-result', 'shared-result', 'shared-result'], $results);
    }

    #[Test]
    public function error_propagates_to_all_waiters(): void
    {
        $group = new SingleflightGroup();

        $task = (static fn() => $group->do('fail-key', static function () {
            delay(0.05);
            throw new RuntimeException('boom');
        }));

        $promises = [
            async($task)(),
            async($task)(),
        ];

        $errors = [];
        foreach ($promises as $promise) {
            try {
                await($promise);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->assertSame(['boom', 'boom'], $errors);
    }

    #[Test]
    public function key_is_cleared_after_completion_allowing_re_execution(): void
    {
        $group = new SingleflightGroup();
        $calls = 0;

        $group->do('reuse', static function () use (&$calls) {
            $calls++;
            return 'first';
        });

        $result = $group->do('reuse', static function () use (&$calls) {
            $calls++;
            return 'second';
        });

        $this->assertSame(2, $calls);
        $this->assertSame('second', $result);
    }

    #[Test]
    public function different_keys_execute_independently(): void
    {
        $group = new SingleflightGroup();
        $executions = [];

        $group->do('a', static function () use (&$executions) {
            $executions[] = 'a';
            return 'result-a';
        });

        $group->do('b', static function () use (&$executions) {
            $executions[] = 'b';
            return 'result-b';
        });

        $this->assertSame(['a', 'b'], $executions);
    }
}

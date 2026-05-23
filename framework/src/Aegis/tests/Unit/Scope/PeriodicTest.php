<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Concurrency\Co;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\PeriodicSubscription;
use Phalanx\Scope\Subscription;
use Phalanx\Testing\PhalanxTestCase;

final class PeriodicTest extends PhalanxTestCase
{
    public function testPeriodicTickFiresMultipleTimes(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $count = 0;
            $countRef = static function () use (&$count): int {
                return $count;
            };

            $sub = $scope->periodic(0.02, static function () use (&$count): void {
                $count++;
            });

            self::assertInstanceOf(PeriodicSubscription::class, $sub);
            self::assertFalse($sub->cancelled);

            Co::sleep(0.1);
            $sub->cancel();

            self::assertGreaterThanOrEqual(3, $countRef());
            self::assertTrue($sub->cancelled);
        });
    }

    public function testCancelStopsTheTimer(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $count = 0;

            $sub = $scope->periodic(0.02, static function () use (&$count): void {
                $count++;
            });

            Co::sleep(0.05);
            $sub->cancel();
            $atCancel = $count;

            Co::sleep(0.1);

            self::assertSame($atCancel, $count, 'tick must not fire after cancel()');
        });
    }

    public function testCancelIsIdempotent(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $sub = $scope->periodic(0.05, static function (): void {
            });

            $sub->cancel();
            $sub->cancel();
            $sub->cancel();

            self::assertTrue($sub->cancelled);
        });
    }

    public function testScopeDisposalCancelsThePeriodic(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $count = 0;

            $sub = null;
            $scopeBody = static function (ExecutionScope $inner) use (&$count, &$sub): void {
                $sub = $inner->periodic(0.02, static function () use (&$count): void {
                    $count++;
                });
                Co::sleep(0.05);
            };

            \Phalanx\Testing\TestScope::compile()
                ->shutdownAfterRun()
                ->run($scopeBody);

            $afterDispose = $count;
            Co::sleep(0.1);

            self::assertInstanceOf(Subscription::class, $sub);
            self::assertSame($afterDispose, $count, 'tick must not fire after scope disposal');
            self::assertTrue($sub->cancelled);
        });
    }

    public function testTickExceptionsDoNotKillTheLoop(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $count = 0;

            $sub = $scope->periodic(0.02, static function () use (&$count): void {
                $count++;
                if ($count === 1) {
                    throw new \RuntimeException('first tick fails');
                }
            });

            Co::sleep(0.1);
            $sub->cancel();

            self::assertGreaterThan(1, $count, 'subsequent ticks must fire even after a tick error');
        });
    }
}

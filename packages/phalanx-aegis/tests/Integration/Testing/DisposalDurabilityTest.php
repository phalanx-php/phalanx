<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Testing;

use Phalanx\ExecutionScope;
use Phalanx\Support\ErrorHandler;
use Phalanx\Task\Task;
use Phalanx\Testing\Probe\DisposalProbe;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DisposalDurabilityTest extends TestCase
{
    #[Test]
    public function dispose_continues_past_callback_failure(): void
    {
        ErrorHandler::use(static function (): void {});
        $probe = new DisposalProbe();

        try {
            TestScope::run(static function (ExecutionScope $scope) use ($probe): void {
                $scope->onDispose($probe->track('first'));
                $scope->onDispose(static function (): void {
                    throw new RuntimeException('dispose bomb');
                });
                $scope->onDispose($probe->track('third'));
            });

            $probe->assertDisposed('third', 'first');
        } finally {
            ErrorHandler::reset();
        }
    }

    #[Test]
    public function nested_scope_disposal_cascades(): void
    {
        $probe = new DisposalProbe();

        TestScope::run(static function (ExecutionScope $scope) use ($probe): void {
            $scope->onDispose($probe->track('outer'));

            $scope->execute(Task::of(static function (ExecutionScope $inner) use ($probe): void {
                $inner->onDispose($probe->track('inner'));

                $inner->execute(Task::of(static function (ExecutionScope $deep) use ($probe): void {
                    $deep->onDispose($probe->track('deep'));
                }));
            }));
        });

        $probe->assertDisposed('deep', 'inner', 'outer');
    }

    #[Test]
    public function concurrent_fiber_dispose_callbacks_fire(): void
    {
        $probe = new DisposalProbe();

        TestScope::run(static function (ExecutionScope $scope) use ($probe): void {
            $scope->concurrent([
                Task::of(static function (ExecutionScope $s) use ($probe): void {
                    $s->onDispose($probe->track('fiber-a'));
                    $s->delay(0.01);
                }),
                Task::of(static function (ExecutionScope $s) use ($probe): void {
                    $s->onDispose($probe->track('fiber-b'));
                    $s->delay(0.01);
                }),
            ]);
        });

        $probe->assertDisposed('fiber-a', 'fiber-b');
    }

    #[Test]
    public function nested_failure_still_disposes_all_levels(): void
    {
        $probe = new DisposalProbe();

        try {
            TestScope::run(static function (ExecutionScope $scope) use ($probe): void {
                $scope->onDispose($probe->track('outer'));

                $scope->execute(Task::of(static function (ExecutionScope $inner) use ($probe): void {
                    $inner->onDispose($probe->track('inner'));
                    throw new RuntimeException('deep failure');
                }));
            });
        } catch (RuntimeException) {
        }

        $probe->assertDisposed('inner', 'outer');
    }
}

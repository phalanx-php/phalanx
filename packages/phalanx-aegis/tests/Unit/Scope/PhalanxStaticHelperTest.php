<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use OpenSwoole\Coroutine;
use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\OutsideScopeException;
use Phalanx\Phalanx;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;

final class PhalanxStaticHelperTest extends CoroutineTestCase
{
    public function testScopeReturnsCurrentlyInstalledScope(): void
    {
        $this->runInCoroutine(function (): void {
            $app = self::buildApp();
            $scope = $app->createScope();

            $observed = null;
            $scope->execute(Task::of(static function (ExecutionScope $s) use (&$observed): void {
                $observed = Phalanx::scope();
            }));

            self::assertNotNull($observed);
            self::assertInstanceOf(\Phalanx\Scope\Scope::class, $observed);

            $scope->dispose();
        });
    }

    public function testScopeThrowsOutsideAnyInstalledScope(): void
    {
        // Run in a fresh coroutine with NO scope installed.
        self::bootCoroutineRuntime();
        $caught = null;
        Coroutine::run(static function () use (&$caught): void {
            try {
                Phalanx::scope();
            } catch (OutsideScopeException $e) {
                $caught = $e;
            }
        });

        self::assertNotNull($caught);
        self::assertStringContainsString('outside any installed scope', $caught->getMessage());
        self::assertStringContainsString('$scope->go', $caught->getMessage());
    }

    public function testTryScopeReturnsNullOutsideScope(): void
    {
        self::bootCoroutineRuntime();
        $observed = 'sentinel';
        Coroutine::run(static function () use (&$observed): void {
            $observed = Phalanx::tryScope();
        });
        self::assertNull($observed);
    }

    public function testEachCoroutineSeesItsOwnInstalledScope(): void
    {
        $this->runInCoroutine(function (): void {
            $app = self::buildApp();
            $scopeA = $app->createScope();
            $scopeB = $app->createScope();

            $observedA = null;
            $observedB = null;

            $scopeA->concurrent(
                a: Task::of(static function (ExecutionScope $s) use (&$observedA): void {
                    $observedA = Phalanx::scope();
                }),
            );

            $scopeB->concurrent(
                b: Task::of(static function (ExecutionScope $s) use (&$observedB): void {
                    $observedB = Phalanx::scope();
                }),
            );

            self::assertNotNull($observedA);
            self::assertNotNull($observedB);
            self::assertNotSame(
                spl_object_id($observedA),
                spl_object_id($observedB),
                'each coroutine must see its own scope',
            );

            $scopeA->dispose();
            $scopeB->dispose();
        });
    }

    private static function buildApp(): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        return Application::starting()
            ->providers($bundle)
            ->withLedger(new InProcessLedger())
            ->compile();
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Lenses;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\TestCase;

final class ScopeLensTest extends TestCase
{
    public function testLensIsAvailable(): void
    {
        $app = TestApp::boot();

        try {
            self::assertInstanceOf(ScopeLens::class, $app->scope);
        } finally {
            $app->shutdown();
        }
    }

    public function testCurrentReturnsNullOutsideOfRun(): void
    {
        $app = TestApp::boot();

        try {
            self::assertNull($app->scope->current());
        } finally {
            $app->shutdown();
        }
    }

    public function testCurrentResolvesActiveScopeDuringRun(): void
    {
        $app = TestApp::boot();

        try {
            $observed = null;

            $app->application->scoped(Task::named(
                'demo.scope.observe',
                static function (ExecutionScope $_scope) use ($app, &$observed): void {
                    $observed = $app->scope->current();
                },
            ));

            self::assertNotNull($observed);
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertDisposedPassesOnIdleApp(): void
    {
        $app = TestApp::boot();

        try {
            $app->scope->assertDisposed();
        } finally {
            $app->shutdown();
        }
    }

    public function testLiveCountReflectsSupervisor(): void
    {
        $app = TestApp::boot();

        try {
            self::assertSame(
                $app->application->supervisor()->liveScopeCount(),
                $app->scope->liveCount(),
            );
        } finally {
            $app->shutdown();
        }
    }
}

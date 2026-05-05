<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Handler;

use Phalanx\Application;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Tests\Fixtures\Handlers\MiddlewareDeclaringHandler;
use Phalanx\Tests\Fixtures\Handlers\PrefixingMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the three-layer middleware composition implemented in
 * HandlerGroup::executeHandler:
 *   group-level (outermost) -> handler-config -> handler-instance HasMiddleware (innermost)
 * with class-string deduplication keeping the LAST occurrence.
 */
final class HasMiddlewareDispatchTest extends TestCase
{
    private Application $app;

    #[Test]
    public function instance_middleware_runs_innermost_around_handler(): void
    {
        $group = HandlerGroup::of([
            'h' => Handler::of(MiddlewareDeclaringHandler::class),
        ])->wrap(PrefixingMiddleware::class);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'h');

        $result = $scope->execute($group);

        // Group middleware (PrefixingMiddleware) wraps the chain outermost,
        // instance middleware (InstanceMiddleware) wraps the handler innermost.
        // Expected order: before:instance(core):after
        $this->assertSame('before:instance(core):after', $result);
    }

    #[Test]
    public function instance_middleware_runs_alone_when_no_group_middleware(): void
    {
        $group = HandlerGroup::of([
            'h' => Handler::of(MiddlewareDeclaringHandler::class),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'h');

        $result = $scope->execute($group);

        $this->assertSame('instance(core)', $result);
    }

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }
}

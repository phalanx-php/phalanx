<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Integration\Handler;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Runtime\Tests\Fixtures\Handlers\MiddlewareDeclaringHandler;
use Phalanx\Runtime\Tests\Fixtures\Handlers\PrefixingMiddleware;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Attributes\Test;

final class HasMiddlewareDispatchTest extends PhalanxTestCase
{
    private TestApp $testApp;

    #[Test]
    public function instance_middleware_runs_innermost_around_handler(): void
    {
        $group = HandlerGroup::of([
            'h' => Handler::of(MiddlewareDeclaringHandler::class),
        ])->wrap(PrefixingMiddleware::class);

        $scope = $this->testApp->application->createScope();

        $result = $group->dispatch('h', $scope);

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

        $scope = $this->testApp->application->createScope();

        $result = $group->dispatch('h', $scope);

        $this->assertSame('instance(core)', $result);
    }

    protected function setUp(): void
    {
        $this->testApp = $this->testApp();
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Integration\Handler;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Runtime\Tests\Fixtures\Handlers\HandlerA;
use Phalanx\Runtime\Tests\Fixtures\Handlers\HandlerB;
use Phalanx\Runtime\Tests\Fixtures\Handlers\PrefixingMiddleware;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Attributes\Test;

final class HandlerDispatchTest extends PhalanxTestCase
{
    private TestApp $testApp;

    #[Test]
    public function dispatches_by_handler_key(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(HandlerA::class),
            'task-b' => Handler::of(HandlerB::class),
        ]);

        $scope = $this->testApp->application->createScope();

        $result = $group->dispatch('task-b', $scope);

        $this->assertSame('b', $result);
    }

    #[Test]
    public function throws_when_handler_key_not_found(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(HandlerA::class),
        ]);

        $scope = $this->testApp->application->createScope();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler not found: nonexistent');

        $group->dispatch('nonexistent', $scope);
    }

    #[Test]
    public function dispatches_via_registered_matcher(): void
    {
        $matcher = new class implements HandlerMatcher {
            public function match(ExecutionScope $scope, array $handlers): ?MatchResult
            {
                $handler = $handlers['task-b'] ?? null;
                if ($handler === null) {
                    return null;
                }

                return new MatchResult($handler, $scope);
            }
        };

        $group = HandlerGroup::of([
            'task-a' => Handler::of(HandlerA::class),
            'task-b' => Handler::of(HandlerB::class),
        ])->withMatcher($matcher);

        $scope = $this->testApp->application->createScope();

        $result = $scope->execute($group);

        $this->assertSame('b', $result);
    }

    #[Test]
    public function throws_when_no_matcher_handles_scope(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(HandlerA::class),
        ]);

        $scope = $this->testApp->application->createScope();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no matcher could handle this scope');

        $scope->execute($group);
    }

    #[Test]
    public function applies_group_middleware_with_key_dispatch(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(HandlerA::class),
        ])->wrap(PrefixingMiddleware::class);

        $scope = $this->testApp->application->createScope();

        $result = $group->dispatch('task-a', $scope);

        $this->assertSame('before:a:after', $result);
    }

    protected function setUp(): void
    {
        $this->testApp = $this->testApp();
    }
}

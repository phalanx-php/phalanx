<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Reactor;

use Phalanx\Theatron\Reactor\ReactorExclusivity;
use Phalanx\Theatron\Reactor\ReactorGroup;
use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Tests\Unit\Reactor\Fixtures\StubReactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReactorGroupTest extends TestCase
{
    #[Test]
    public function register_adds_reactor(): void
    {
        $group = new ReactorGroup();
        $reactor = new StubReactor('agent-1');

        $group->register($reactor);

        self::assertSame($reactor, $group->get('agent-1'));
    }

    #[Test]
    public function register_exclusive_cancels_previous(): void
    {
        $group = new ReactorGroup();
        $first = new StubReactor('agent-1');
        $second = new StubReactor('agent-1');

        $group->register($first);
        $group->register($second);

        self::assertTrue($first->wasCancelled);
        self::assertSame($second, $group->get('agent-1'));
    }

    #[Test]
    public function register_concurrent_does_not_cancel(): void
    {
        $group = new ReactorGroup();
        $first = new StubReactor('worker', exclusivityMode: ReactorExclusivity::Concurrent);
        $second = new StubReactor('worker', exclusivityMode: ReactorExclusivity::Concurrent);

        $group->register($first);
        $group->register($second);

        self::assertFalse($first->wasCancelled);
    }

    #[Test]
    public function cancel_by_id(): void
    {
        $group = new ReactorGroup();
        $reactor = new StubReactor('agent-1');
        $group->register($reactor);

        $group->cancel('agent-1');

        self::assertTrue($reactor->wasCancelled);
    }

    #[Test]
    public function cancel_unknown_id_is_no_op(): void
    {
        $group = new ReactorGroup();

        $group->cancel('nonexistent');

        self::assertNull($group->get('nonexistent'));
    }

    #[Test]
    public function cancel_group_cancels_matching_reactors(): void
    {
        $group = new ReactorGroup();
        $a = new StubReactor('r1', group: 'agents');
        $b = new StubReactor('r2', group: 'agents');
        $c = new StubReactor('r3', group: 'workers');

        $group->register($a);
        $group->register($b);
        $group->register($c);

        $group->cancelGroup('agents');

        self::assertTrue($a->wasCancelled);
        self::assertTrue($b->wasCancelled);
        self::assertFalse($c->wasCancelled);
    }

    #[Test]
    public function cancel_all_cancels_every_reactor(): void
    {
        $group = new ReactorGroup();
        $a = new StubReactor('r1');
        $b = new StubReactor('r2');

        $group->register($a);
        $group->register($b);

        $group->cancelAll();

        self::assertTrue($a->wasCancelled);
        self::assertTrue($b->wasCancelled);
    }

    #[Test]
    public function states_returns_all_reactor_states(): void
    {
        $group = new ReactorGroup();
        $group->register(new StubReactor('a'));
        $group->register(new StubReactor('b'));

        $states = $group->states();

        self::assertSame(ReactorState::Idle, $states['a']);
        self::assertSame(ReactorState::Idle, $states['b']);
    }

    #[Test]
    public function state_of_filters_by_group(): void
    {
        $group = new ReactorGroup();
        $group->register(new StubReactor('a', group: 'agents'));
        $group->register(new StubReactor('b', group: 'workers'));

        $states = $group->stateOf('agents');

        self::assertCount(1, $states);
        self::assertArrayHasKey('a', $states);
    }

    #[Test]
    public function get_returns_null_for_missing(): void
    {
        $group = new ReactorGroup();

        self::assertNull($group->get('nope'));
    }
}

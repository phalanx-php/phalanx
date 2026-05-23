<?php

declare(strict_types=1);

namespace Phalanx\Tests\Eidolon\Unit;

use Phalanx\Eidolon\Signal\EventSignal;
use Phalanx\Eidolon\Signal\FlashSignal;
use Phalanx\Eidolon\Signal\InvalidateSignal;
use Phalanx\Eidolon\Signal\RedirectSignal;
use Phalanx\Eidolon\Signal\SignalCollector;
use Phalanx\Eidolon\Signal\TokenSignal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignalCollectorTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $collector = new SignalCollector();
        $this->assertTrue($collector->isEmpty());
        $this->assertSame([], $collector->drain());
    }

    #[Test]
    public function add_makes_non_empty(): void
    {
        $collector = new SignalCollector();
        $collector->add(new FlashSignal('hello'));
        $this->assertFalse($collector->isEmpty());
    }

    #[Test]
    public function drain_clears_queue(): void
    {
        $collector = new SignalCollector();
        $collector->flash('hello');

        $signals = $collector->drain();
        $this->assertCount(1, $signals);
        $this->assertTrue($collector->isEmpty());
        $this->assertSame([], $collector->drain());
    }

    #[Test]
    public function drain_sorts_by_priority(): void
    {
        $collector = new SignalCollector();
        $collector->add(new RedirectSignal('/home'));         // priority 4
        $collector->add(new FlashSignal('saved'));            // priority 2
        $collector->add(new InvalidateSignal('users'));       // priority 0
        $collector->add(new TokenSignal('abc123'));           // priority 1
        $collector->add(new EventSignal('user.created'));     // priority 3

        $signals = $collector->drain();

        $this->assertSame('invalidate', $signals[0]['type']);
        $this->assertSame('token', $signals[1]['type']);
        $this->assertSame('flash', $signals[2]['type']);
        $this->assertSame('event', $signals[3]['type']);
        $this->assertSame('redirect', $signals[4]['type']);
    }

    #[Test]
    public function fluent_flash(): void
    {
        $collector = new SignalCollector();
        $result = $collector->flash('msg', 'error');

        $this->assertSame($collector, $result);
        $signals = $collector->drain();
        $this->assertSame('flash', $signals[0]['type']);
        $this->assertSame('msg', $signals[0]['message']);
        $this->assertSame('error', $signals[0]['level']);
    }

    #[Test]
    public function fluent_invalidate(): void
    {
        $collector = new SignalCollector();
        $collector->invalidate('users', 'posts');

        $signals = $collector->drain();
        $this->assertSame(['users', 'posts'], $signals[0]['keys']);
    }

    #[Test]
    public function fluent_redirect(): void
    {
        $collector = new SignalCollector();
        $collector->redirect('/login', replace: true);

        $signals = $collector->drain();
        $this->assertSame('/login', $signals[0]['to']);
        $this->assertTrue($signals[0]['replace']);
    }

    #[Test]
    public function fluent_event(): void
    {
        $collector = new SignalCollector();
        $collector->event('order.placed', ['id' => 42]);

        $signals = $collector->drain();
        $this->assertSame('order.placed', $signals[0]['name']);
        $this->assertSame(['id' => 42], $signals[0]['payload']);
    }

    #[Test]
    public function fluent_token(): void
    {
        $collector = new SignalCollector();
        $collector->token('jwt-abc', expiresIn: 3600);

        $signals = $collector->drain();
        $this->assertSame('jwt-abc', $signals[0]['token']);
        $this->assertSame(3600, $signals[0]['expires_in']);
    }

    #[Test]
    public function flash_rejects_invalid_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FlashSignal('msg', 'critical');
    }
}

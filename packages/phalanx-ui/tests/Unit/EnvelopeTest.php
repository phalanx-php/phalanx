<?php

declare(strict_types=1);

namespace Phalanx\Tests\Ui\Unit;

use Phalanx\Ui\Signal\Envelope;
use Phalanx\Ui\Signal\SignalCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    #[Test]
    public function wrap_produces_envelope_structure(): void
    {
        $collector = new SignalCollector();
        $collector->flash('saved', 'success');

        $envelope = Envelope::wrap(['id' => 1], $collector, 'trace-abc');

        $this->assertSame(['id' => 1], $envelope['data']);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertSame('trace-abc', $envelope['meta']['trace_id']);
        $this->assertIsInt($envelope['meta']['timestamp']);
        $this->assertCount(1, $envelope['meta']['signals']);
        $this->assertSame('flash', $envelope['meta']['signals'][0]['type']);
    }

    #[Test]
    public function wrap_drains_collector(): void
    {
        $collector = new SignalCollector();
        $collector->flash('a');
        $collector->flash('b');

        Envelope::wrap(null, $collector);

        $this->assertTrue($collector->isEmpty());
    }

    #[Test]
    public function wrap_with_empty_signals(): void
    {
        $collector = new SignalCollector();
        $envelope = Envelope::wrap(['ok' => true], $collector);

        $this->assertSame([], $envelope['meta']['signals']);
        $this->assertNull($envelope['meta']['trace_id']);
    }

    #[Test]
    public function wrap_with_null_data(): void
    {
        $collector = new SignalCollector();
        $envelope = Envelope::wrap(null, $collector);

        $this->assertNull($envelope['data']);
    }

    #[Test]
    public function is_envelope_detects_valid_envelope(): void
    {
        $collector = new SignalCollector();
        $envelope = Envelope::wrap(['ok' => true], $collector);

        $this->assertTrue(Envelope::isEnvelope($envelope));
    }

    #[Test]
    public function is_envelope_rejects_plain_array(): void
    {
        $this->assertFalse(Envelope::isEnvelope(['ok' => true]));
        $this->assertFalse(Envelope::isEnvelope(['data' => true]));
        $this->assertFalse(Envelope::isEnvelope(['data' => true, 'meta' => 'string']));
        $this->assertFalse(Envelope::isEnvelope(['data' => true, 'meta' => []]));
    }

    #[Test]
    public function is_envelope_rejects_non_array(): void
    {
        $this->assertFalse(Envelope::isEnvelope('string'));
        $this->assertFalse(Envelope::isEnvelope(42));
        $this->assertFalse(Envelope::isEnvelope(null));
    }

    #[Test]
    public function is_envelope_detects_null_data_envelope(): void
    {
        $this->assertTrue(Envelope::isEnvelope([
            'data' => null,
            'meta' => ['signals' => [], 'timestamp' => 0, 'trace_id' => null],
        ]));
    }
}

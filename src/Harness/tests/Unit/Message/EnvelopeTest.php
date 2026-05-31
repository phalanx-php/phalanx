<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Message;

use DateTimeImmutable;
use Phalanx\Harness\Boundary\Urgency;
use Phalanx\Harness\Message\Address;
use Phalanx\Harness\Message\Envelope;
use Phalanx\Harness\Message\MessageKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    #[Test]
    public function promptFactoryBuildsUserPromptForPrimaryAgent(): void
    {
        $envelope = Envelope::prompt('Ship AH-1');

        self::assertStringStartsWith('env_', $envelope->id);
        self::assertSame('user', $envelope->from->identity);
        self::assertSame('agent:primary', $envelope->to->identity);
        self::assertSame(MessageKind::Prompt, $envelope->kind);
        self::assertSame('Ship AH-1', $envelope->payload);
        self::assertSame(0, $envelope->priority);
    }

    #[Test]
    public function observationFactoryMapsUrgencyToAlertAndPriority(): void
    {
        $envelope = Envelope::observation('daemon8', ['severity' => 'error'], Urgency::Interrupt);

        self::assertSame('service:daemon8', $envelope->from->identity);
        self::assertSame(MessageKind::Alert, $envelope->kind);
        self::assertSame(100, $envelope->priority);
    }

    #[Test]
    public function envelopeCanonicalFormAndHashAreStable(): void
    {
        $first = Envelope::make(
            from: Address::user('operator'),
            to: Address::agent('assistant'),
            kind: MessageKind::Prompt,
            payload: ['text' => 'hello'],
            correlationId: 'corr_1',
            priority: 10,
            tags: ['alpha', 'alpha', 'beta'],
            at: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'env_test',
        );
        $second = Envelope::make(
            from: Address::user('operator'),
            to: Address::agent('assistant'),
            kind: MessageKind::Prompt,
            payload: ['text' => 'hello'],
            correlationId: 'corr_1',
            priority: 10,
            tags: ['alpha', 'beta'],
            at: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'env_test',
        );

        self::assertSame($first->hash(), $second->hash());
    }
}

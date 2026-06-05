<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit\Stream;

use Phalanx\Agents\Stream\ArrayCueEmitter;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayCueEmitterTest extends TestCase
{
    #[Test]
    public function drainReturnsCuesAndResets(): void
    {
        $at = new \DateTimeImmutable('2026-05-19T10:00:00Z');
        $emitter = new ArrayCueEmitter();

        $emitter->emit(new TokenDelta('cue_1', 1, 'act_1', null, 'agent_1', $at, 'alpha'));
        $emitter->emit(new TokenDelta('cue_2', 2, 'act_1', null, 'agent_1', $at, 'beta'));

        $drained = $emitter->drain();

        self::assertCount(2, $drained);
        self::assertInstanceOf(TokenDelta::class, $drained[0]);
        self::assertInstanceOf(TokenDelta::class, $drained[1]);
        self::assertSame('alpha', $drained[0]->text);
        self::assertSame('beta', $drained[1]->text);

        self::assertSame([], $emitter->drain());
    }

    #[Test]
    public function emitAccumulatesCues(): void
    {
        $at = new \DateTimeImmutable('2026-05-19T10:00:00Z');
        $emitter = new ArrayCueEmitter();

        self::assertSame([], $emitter->cues);

        $emitter->emit(new TokenDelta('cue_1', 1, 'act_1', null, 'agent_1', $at, 'first'));

        self::assertCount(1, $emitter->cues);
    }
}

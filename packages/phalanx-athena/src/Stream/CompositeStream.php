<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;

final class CompositeStream
{
    /** @var list<Cue> */
    private array $hostCues = [];

    private function __construct(
        private(set) Stream $provider,
        private(set) TaskScope $scope,
    ) {
    }

    public static function wrap(TaskScope $scope, Stream $provider): self
    {
        return new self($provider, $scope);
    }

    public function emit(Cue $cue): void
    {
        $this->hostCues[] = $cue;
        usort($this->hostCues, self::compare(...));
    }

    public function stream(): Stream
    {
        $self = $this;

        return new Stream(static function () use ($self): \Generator {
            $provider = $self->provider->getIterator();
            $hostIndex = 0;

            foreach ($provider as $cue) {
                $self->scope->throwIfCancelled();
                yield from $self->drainHostCuesThrough($hostIndex, $cue->sequence);
                yield $cue;
            }

            yield from $self->drainHostCues($hostIndex);
        });
    }

    private static function compare(Cue $left, Cue $right): int
    {
        return $left->sequence <=> $right->sequence;
    }

    /** @return \Generator<Cue> */
    private function drainHostCuesThrough(int &$hostIndex, int $sequence): \Generator
    {
        while (isset($this->hostCues[$hostIndex]) && $this->hostCues[$hostIndex]->sequence <= $sequence) {
            yield $this->hostCues[$hostIndex];
            $hostIndex++;
        }
    }

    /** @return \Generator<Cue> */
    private function drainHostCues(int &$hostIndex): \Generator
    {
        while (isset($this->hostCues[$hostIndex])) {
            $this->scope->throwIfCancelled();
            yield $this->hostCues[$hostIndex];
            $hostIndex++;
        }
    }
}

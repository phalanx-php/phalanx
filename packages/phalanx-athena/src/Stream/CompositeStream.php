<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;

final class CompositeStream implements CueEmitter
{
    /** @var list<Cue> */
    private array $hostCues = [];

    private function __construct(
        private Stream $provider,
        private TaskScope $scope,
    ) {
    }

    public static function wrap(TaskScope $scope, Stream $provider): self
    {
        return new self($provider, $scope);
    }

    public function emit(Cue $cue): void
    {
        $pos = count($this->hostCues);
        while ($pos > 0 && $this->hostCues[$pos - 1]->sequence > $cue->sequence) {
            $pos--;
        }
        array_splice($this->hostCues, $pos, 0, [$cue]);
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

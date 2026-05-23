<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Kit\TextInputBehavior;
use Phalanx\Theatron\Reactive\Signal;

final class TextInputFixture
{
    use TextInputBehavior;

    public function __construct(
        private ?Signal $signal,
        private ?Signal $cursor = null,
        private ?Signal $killRing = null,
    ) {
    }

    public function signal(): ?Signal
    {
        return $this->signal;
    }

    public function cursor(): ?Signal
    {
        return $this->cursor;
    }

    public function killRing(): ?Signal
    {
        return $this->killRing;
    }

    public function handle(KeyEvent $event): bool
    {
        if ($this->signal === null) {
            return false;
        }

        return $this->handleTextInput($event);
    }

    protected function inputSignal(): Signal
    {
        return $this->signal ?? throw new \RuntimeException('Text input signal is not configured.');
    }

    protected function inputCursorSignal(): Signal
    {
        return $this->cursor ??= new Signal(mb_strlen((string) ($this->signal?->get() ?? '')));
    }

    protected function inputKillRingSignal(): Signal
    {
        return $this->killRing ??= new Signal('');
    }
}

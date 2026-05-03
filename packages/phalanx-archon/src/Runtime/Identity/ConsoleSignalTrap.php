<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Closure;

/** @internal */
final class ConsoleSignalTrap
{
    private bool $installed = false;

    private ?bool $previousAsyncSignals = null;

    /** @var array<int, callable|int> */
    private array $previousHandlers = [];

    private function __construct()
    {
    }

    /** @param Closure(ConsoleSignal): void $onSignal */
    public static function install(ConsoleSignalPolicy $policy, Closure $onSignal): self
    {
        $trap = new self();
        if (
            $policy->exitCodes() === []
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_async_signals')
        ) {
            return $trap;
        }

        $trap->previousAsyncSignals = pcntl_async_signals(true);

        foreach ($policy->exitCodes() as $number => $exitCode) {
            $signal = $policy->signal($number);
            if ($signal === null) {
                continue;
            }

            $trap->previousHandlers[$number] = pcntl_signal_get_handler($number);
            pcntl_signal($number, static function () use ($signal, $onSignal): void {
                $onSignal($signal);
            });
        }

        $trap->installed = true;

        return $trap;
    }

    public function restore(): void
    {
        if (!$this->installed) {
            return;
        }

        foreach ($this->previousHandlers as $number => $handler) {
            pcntl_signal($number, $handler);
        }

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
        }

        $this->installed = false;
        $this->previousHandlers = [];
        $this->previousAsyncSignals = null;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Closure;
use Phalanx\Runtime\Swoole\SwooleRuntime;

/**
 * Installs a policy's signal handlers on the Swoole reactor.
 *
 * Each registered signal forwards to the supplied $onSignal closure with the
 * matching ConsoleSignal value. restore() clears the registrations by passing
 * `null` through SwooleRuntime::signal(), which is the reactor's documented
 * removal contract. We do not snapshot prior handlers because the reactor exposes no
 * read API for them; consumers that need to coexist with foreign handlers
 * must reinstall after restore().
 *
 * Skip-guard: install() is a no-op when the policy is empty or the swoole
 * extension is not loaded. Process::signal requires a running reactor to
 * dispatch, but registration itself can occur before Coroutine::run starts —
 * the binding survives and fires once the loop is up.
 *
 * @internal
 */
final class ConsoleSignalTrap
{
    private bool $installed = false;

    /** @var list<int> */
    private array $registered = [];

    private function __construct()
    {
    }

    /** @param Closure(ConsoleSignal): void $onSignal */
    public static function install(ConsoleSignalPolicy $policy, Closure $onSignal): self
    {
        $trap = new self();

        if ($policy->exitCodes() === [] || !extension_loaded('swoole')) {
            return $trap;
        }

        foreach ($policy->exitCodes() as $number => $exitCode) {
            $signal = $policy->signal($number);
            if ($signal === null) {
                continue;
            }

            SwooleRuntime::signal($number, static function () use ($signal, $onSignal): void {
                $onSignal($signal);
            });
            $trap->registered[] = $number;
        }

        $trap->installed = $trap->registered !== [];

        return $trap;
    }

    public function restore(): void
    {
        if (!$this->installed) {
            return;
        }

        foreach ($this->registered as $number) {
            SwooleRuntime::signal($number, null);
        }

        $this->installed = false;
        $this->registered = [];
    }
}

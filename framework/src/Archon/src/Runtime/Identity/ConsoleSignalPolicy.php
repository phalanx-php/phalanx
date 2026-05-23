<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use InvalidArgumentException;

/**
 * Declarative SIGINT/SIGTERM/etc → exit-code mapping consumed by
 * {@see ConsoleSignalTrap}. Constructed via the static factories so the
 * exit-code map is validated at the boundary.
 */
final readonly class ConsoleSignalPolicy
{
    /** @param array<int, int> $exitCodes */
    private function __construct(private array $exitCodes)
    {
    }

    public static function default(): self
    {
        if (!extension_loaded('openswoole')) {
            return self::disabled();
        }

        $signals = [];
        if (defined('SIGINT')) {
            $signals[SIGINT] = 130;
        }
        if (defined('SIGTERM')) {
            $signals[SIGTERM] = 143;
        }

        return new self($signals);
    }

    public static function disabled(): self
    {
        return new self([]);
    }

    /** @param array<int, int> $exitCodes */
    public static function forSignals(array $exitCodes): self
    {
        foreach ($exitCodes as $signal => $exitCode) {
            if (!is_int($signal) || !is_int($exitCode) || $signal <= 0 || $exitCode <= 0) {
                throw new InvalidArgumentException('Signal policies require positive integer signals and exit codes.');
            }

            if ($signal > 64) {
                throw new InvalidArgumentException('Signal policies require POSIX signal numbers (1-64).');
            }
        }

        return new self($exitCodes);
    }

    /** @return array<int, int> */
    public function exitCodes(): array
    {
        return $this->exitCodes;
    }

    public function signal(int $number): ?ConsoleSignal
    {
        $exitCode = $this->exitCodes[$number] ?? null;
        if ($exitCode === null) {
            return null;
        }

        return new ConsoleSignal(
            number: $number,
            exitCode: $exitCode,
            reason: self::reason($number),
        );
    }

    private static function reason(int $number): string
    {
        if (defined('SIGINT') && $number === SIGINT) {
            return 'signal:int';
        }
        if (defined('SIGTERM') && $number === SIGTERM) {
            return 'signal:term';
        }

        return "signal:$number";
    }
}

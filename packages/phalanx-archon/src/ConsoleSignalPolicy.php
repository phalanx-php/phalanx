<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use InvalidArgumentException;
use ValueError;

final readonly class ConsoleSignalPolicy
{
    /** @param array<int, int> $exitCodes */
    private function __construct(private array $exitCodes)
    {
    }

    public static function default(): self
    {
        if (!function_exists('pcntl_signal')) {
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

            self::assertSupportedSignal($signal);
        }

        return new self($exitCodes);
    }

    private static function assertSupportedSignal(int $signal): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            return;
        }

        try {
            pcntl_signal_get_handler($signal);
        } catch (ValueError) {
            throw new InvalidArgumentException('Signal policies require signals supported by pcntl.');
        }
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
}

<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\System;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

/**
 * Aegis-managed external command primitive.
 *
 * Wraps OpenSwoole\Coroutine\System::exec under a Suspendable::call so the
 * supervisor records the wait, cancellation propagates through scope tear-down,
 * and downstream consumers (Argos PingHost, Enigma SSH transport, Hydra worker
 * spawn diagnostics, Skopos managed processes) share one canonical exec path.
 *
 * Usage:
 *
 *   $result = (new SystemCommand('ping -c 1 -W 2 192.168.1.1'))($scope);
 *   $result->throwIfFailed('ping failed');
 *
 * For safe argument quoting, prefer the named constructor:
 *
 *   $result = SystemCommand::from('ping', '-c', '1', '-W', '2', $ip)($scope);
 *
 * Timeouts are not handled inside this primitive. Wrap in
 * $scope->timeout(seconds, ...) at the call site when the command must be
 * bounded; cancellation propagates into the Coroutine\System call via the
 * scope's cancellation token.
 */
final readonly class SystemCommand
{
    public function __construct(
        public string $command,
        public bool $captureStderr = true,
    ) {
    }

    public static function from(string $binary, string ...$args): self
    {
        $parts = [escapeshellcmd($binary)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        return new self(implode(' ', $parts));
    }

    public function __invoke(Suspendable $scope): CommandResult
    {
        $command = $this->command;
        $captureStderr = $this->captureStderr;

        $startedNs = hrtime(true);

        $raw = $scope->call(
            static fn(): array|false => System::exec($command, $captureStderr),
            WaitReason::process($command),
        );

        $durationMs = (hrtime(true) - $startedNs) / 1_000_000;

        if ($raw === false) {
            throw new SystemCommandException("Failed to execute command: {$command}");
        }

        return new CommandResult(
            command: $command,
            exitCode: self::intField($raw, 'code', -1),
            output: self::stringField($raw, 'output'),
            durationMs: $durationMs,
            signal: self::intField($raw, 'signal', 0),
        );
    }

    /** @param array<string, mixed> $raw */
    private static function intField(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? $default;
        return is_int($value) ? $value : (int) $value;
    }

    /** @param array<string, mixed> $raw */
    private static function stringField(array $raw, string $key): string
    {
        $value = $raw[$key] ?? '';
        return is_string($value) ? $value : (string) $value;
    }
}

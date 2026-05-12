<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Closure;

final class Probe extends BootRequirement
{
    public const string KIND_TCP = 'probe.tcp';
    public const string KIND_HTTP = 'probe.http';
    public const string KIND_CALLABLE = 'probe.callable';

    /** @param Closure(AppContext): BootEvaluation $check */
    private function __construct(
        string $kind,
        string $description,
        private(set) ProbeOutcome $failureMode,
        private Closure $check,
    ) {
        parent::__construct($kind, $description);
    }

    public static function tcp(
        string $host,
        int $port,
        float $timeout = 1.0,
        ProbeOutcome $failureMode = ProbeOutcome::FailBoot,
        ?string $description = null,
    ): self {
        $message = $description ?? sprintf('TCP probe %s:%d', $host, $port);

        return new self(
            self::KIND_TCP,
            $message,
            $failureMode,
            static function (AppContext $_ctx) use ($host, $port, $timeout, $failureMode, $message): BootEvaluation {
                $errno = 0;
                $errstr = '';
                $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
                if ($socket !== false) {
                    fclose($socket);
                    return BootEvaluation::pass(sprintf('%s reachable', $message));
                }
                $remediation = sprintf(
                    'Cannot reach %s:%d (%s). Verify the service is running and reachable.',
                    $host,
                    $port,
                    $errstr ?: 'unknown error',
                );
                if ($failureMode === ProbeOutcome::FailBoot) {
                    return BootEvaluation::fail(sprintf('%s unreachable', $message), $remediation);
                }
                return BootEvaluation::warn(sprintf('%s unreachable; feature unavailable', $message), $remediation);
            },
        );
    }

    /**
     * @param list<int> $expectStatus
     */
    public static function http(
        string $url,
        array $expectStatus = [200],
        float $timeout = 2.0,
        ProbeOutcome $failureMode = ProbeOutcome::FeatureUnavailable,
        ?string $description = null,
    ): self {
        $msg = $description ?? sprintf('HTTP probe %s', $url);
        $expected = $expectStatus;

        $check = static function (AppContext $_ctx) use ($url, $expected, $timeout, $failureMode, $msg): BootEvaluation {
            $context = stream_context_create([
                'http' => ['method' => 'HEAD', 'timeout' => $timeout, 'ignore_errors' => true],
            ]);
            $headers = @get_headers($url, true, $context);
            if ($headers === false) {
                $remediation = sprintf('Cannot reach %s. Verify URL and network connectivity.', $url);
                if ($failureMode === ProbeOutcome::FailBoot) {
                    return BootEvaluation::fail(sprintf('%s unreachable', $msg), $remediation);
                }
                return BootEvaluation::warn(sprintf('%s unreachable; feature unavailable', $msg), $remediation);
            }
            $raw = $headers[0] ?? '';
            $statusLine = is_array($raw) ? ($raw[0] ?? '') : $raw;
            $matched = (bool) preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m);
            $status = $matched ? (int) $m[1] : 0;
            if (in_array($status, $expected, true)) {
                return BootEvaluation::pass(sprintf('%s responded %d', $msg, $status));
            }
            $remediation = sprintf('Expected one of %s, got %d', implode(',', $expected), $status);
            if ($failureMode === ProbeOutcome::FailBoot) {
                return BootEvaluation::fail(sprintf('%s status %d', $msg, $status), $remediation);
            }
            return BootEvaluation::warn(sprintf('%s status %d; feature unavailable', $msg, $status), $remediation);
        };
        return new self(self::KIND_HTTP, $msg, $failureMode, $check);
    }

    public static function callable(
        callable $fn,
        string $description,
        ProbeOutcome $failureMode = ProbeOutcome::FailBoot,
    ): self {

        return new self(
            self::KIND_CALLABLE,
            $description,
            $failureMode,
            static function (AppContext $ctx) use ($fn, $description, $failureMode): BootEvaluation {
                $result = $fn($ctx);
                if ($result instanceof BootEvaluation) {
                    return $result;
                }
                if ($result === true) {
                    return BootEvaluation::pass($description);
                }
                $remediation = is_string($result) ? $result : null;
                if ($failureMode === ProbeOutcome::FailBoot) {
                    return BootEvaluation::fail(sprintf('%s failed', $description), $remediation);
                }
                return BootEvaluation::warn(sprintf('%s failed; feature unavailable', $description), $remediation);
            },
        );
    }

    public function evaluate(AppContext $context): BootEvaluation
    {
        return ($this->check)($context);
    }
}

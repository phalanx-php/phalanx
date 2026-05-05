<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Throwable;

class StreamingProcess
{
    public const int DEFAULT_MAX_LINE_BYTES = 1_048_576;

    /** @var non-empty-list<string> */
    public private(set) array $argv;

    /** @var array<string, string>|null */
    public private(set) ?array $env;

    public private(set) int $maxLineBytes;

    /**
     * @param list<string> $argv
     * @param array<string, string|int|float|bool|null>|null $env
     */
    public function __construct(
        array $argv,
        public private(set) ?string $cwd = null,
        ?array $env = null,
        int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
    ) {
        $this->argv = self::normalizeArgv($argv);
        $this->env = self::normalizeEnv($env);
        $this->maxLineBytes = max(1, $maxLineBytes);
    }

    public static function from(string $binary, string ...$args): self
    {
        /** @var non-empty-list<string> $argv */
        $argv = [$binary, ...$args];

        return new self($argv);
    }

    /** @param list<string> $argv */
    public static function command(array $argv): self
    {
        return new self($argv);
    }

    public function start(TaskScope&TaskExecutor $scope, bool $closeOnScopeDispose = true): StreamingProcessHandle
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw StreamingProcessException::unsupportedPlatform();
        }

        $command = $this->commandLine();
        $pipes = [];
        $error = '';

        set_error_handler(static function (int $_, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });

        try {
            $process = proc_open(
                $this->argv,
                [
                    ['pipe', 'r'],
                    ['pipe', 'w'],
                    ['pipe', 'w'],
                ],
                $pipes,
                $this->cwd,
                $this->env,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            throw StreamingProcessException::startFailed($command, $error !== '' ? $error : 'unknown error');
        }

        $resourceId = null;

        try {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    stream_set_blocking($pipe, false);
                }
            }

            $status = proc_get_status($process);

            $pid = (int) $status['pid'];
            $scopeId = $scope instanceof ScopeIdentity ? $scope->scopeId : null;
            $resource = $scope->runtime->memory->resources->open(
                AegisResourceSid::StreamingProcess,
                ownerScopeId: $scopeId,
                state: ManagedResourceState::Opening,
            );
            $resourceId = $resource->id;
            $scope->runtime->memory->resources->annotate(
                $resource,
                AegisAnnotationSid::ProcessCommand,
                $this->commandHead(),
            );
            $scope->runtime->memory->resources->annotate($resource, AegisAnnotationSid::ProcessPid, $pid);
            $scope->runtime->memory->resources->annotate(
                $resource,
                AegisAnnotationSid::ProcessState,
                StreamingProcessState::Running->value,
            );
            $scope->runtime->memory->resources->annotate($resource, AegisAnnotationSid::ProcessCwd, $this->cwd ?? '');
            $active = $scope->runtime->memory->resources->activate($resource);
            $scope->runtime->memory->resources->recordEvent($active, AegisEventSid::ProcessStarted, (string) $pid);

            $handle = new StreamingProcessHandle(
                process: $process,
                pipes: $pipes,
                scope: $scope,
                resourceId: $active->id,
                commandHead: $this->commandHead(),
                pid: $pid,
                maxLineBytes: $this->maxLineBytes,
            );

            if ($closeOnScopeDispose) {
                $scope->onDispose(static function () use ($handle): void {
                    $handle->close('scope.dispose');
                });
            }

            return $handle;
        } catch (Throwable $e) {
            self::cleanupStartedProcess($process, $pipes);
            if ($resourceId !== null) {
                try {
                    $scope->runtime->memory->resources->fail($resourceId, 'process.start.setup_failed');
                    $scope->runtime->memory->resources->release($resourceId);
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (Throwable) {
                }
            }

            throw $e;
        }
    }

    public function withCwd(?string $cwd): self
    {
        $clone = clone $this;
        $clone->cwd = $cwd;
        return $clone;
    }

    /** @param array<string, string|int|float|bool|null>|null $env */
    public function withEnv(?array $env): self
    {
        $clone = clone $this;
        $clone->env = self::normalizeEnv($env);
        return $clone;
    }

    public function withMaxLineBytes(int $bytes): self
    {
        $clone = clone $this;
        $clone->maxLineBytes = max(1, $bytes);
        return $clone;
    }

    public function commandLine(): string
    {
        return implode(' ', array_map(self::quoteForDisplay(...), $this->argv));
    }

    public function commandHead(): string
    {
        return basename($this->argv[0]);
    }

    /**
     * @param list<string> $argv
     * @return non-empty-list<string>
     */
    private static function normalizeArgv(array $argv): array
    {
        $normalized = array_values($argv);
        if ($normalized === []) {
            throw StreamingProcessException::invalidCommand();
        }

        foreach ($normalized as $arg) {
            if (!is_string($arg) || $arg === '') {
                throw StreamingProcessException::invalidCommand();
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, string|int|float|bool|null>|null $env
     * @return array<string, string>|null
     */
    private static function normalizeEnv(?array $env): ?array
    {
        if ($env === null) {
            return null;
        }

        $normalized = [];
        foreach ($env as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw StreamingProcessException::invalidEnvironment((string) $key);
            }
            $normalized[(string) $key] = $value === null ? '' : (string) $value;
        }

        return $normalized;
    }

    private static function quoteForDisplay(string $arg): string
    {
        if ($arg === '') {
            return "''";
        }
        if (preg_match('/^[A-Za-z0-9_.,:\/@%+=-]+$/', $arg) === 1) {
            return $arg;
        }
        return escapeshellarg($arg);
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private static function cleanupStartedProcess(mixed $process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (!is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);
        if ((bool) $status['running']) {
            proc_terminate($process, 9);
        }

        proc_close($process);
    }
}

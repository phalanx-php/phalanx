<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use Phalanx\Console\Runtime\Identity\ConsoleAnnotationSid;
use Phalanx\Console\Runtime\Identity\ConsoleEventSid;
use Phalanx\Console\Runtime\Identity\ConsoleResourceSid;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\ScopeIdentity;
use Throwable;

/** @internal */
final class CommandLifecycle
{
    public string $resourceId {
        get => $this->handle->id;
    }

    private function __construct(
        private RuntimeContext $runtime,
        private ManagedResourceHandle $handle,
    ) {
    }

    public static function open(
        ExecutionScope&ScopeIdentity $scope,
        string $name,
        int $argumentCount,
        bool $defaultCommand,
    ): self {
        $runtime = $scope->runtime;
        $memory = $runtime->memory;
        $resourceId = $memory->ids->nextRuntime('console-command');
        $handle = $memory->resources->open(
            type: ConsoleResourceSid::Command,
            id: $resourceId,
            parentResourceId: $scope->scopeId,
            ownerScopeId: $scope->scopeId,
        );

        $lifecycle = new self($runtime, $handle);
        $lifecycle->annotate(ConsoleAnnotationSid::CommandName, $name);
        $lifecycle->annotate(ConsoleAnnotationSid::ArgumentCount, $argumentCount);
        $lifecycle->annotate(ConsoleAnnotationSid::DefaultCommand, $defaultCommand);
        $lifecycle->record(ConsoleEventSid::CommandDispatched, $name);

        return $lifecycle;
    }

    public function activate(string $handler): void
    {
        $this->annotate(ConsoleAnnotationSid::Handler, $handler);
        $this->handle = $this->runtime->memory->resources->activate($this->handle);
        $this->record(ConsoleEventSid::CommandMatched, $handler);
    }

    public function close(int $exitCode): void
    {
        $this->annotate(ConsoleAnnotationSid::ExitCode, $exitCode);
        $this->handle = $this->runtime->memory->resources->close($this->handle, "exit:$exitCode");
        $this->record(ConsoleEventSid::CommandCompleted, (string) $exitCode);
    }

    public function fail(string $kind, string $reason = '', ?Throwable $exception = null): void
    {
        $this->annotate(ConsoleAnnotationSid::ExitCode, 1);
        $this->annotate(ConsoleAnnotationSid::ErrorKind, $kind);

        if ($exception !== null) {
            $this->annotate(ConsoleAnnotationSid::ExceptionClass, $exception::class);
        }

        $reason = self::fit($reason);
        $this->handle = $this->runtime->memory->resources->fail($this->handle, $reason);
        $this->record(ConsoleEventSid::CommandFailed, $kind, $reason);
    }

    public function invalidInput(string $reason, ?Throwable $exception = null): void
    {
        $this->fail('invalid_input', $reason, $exception);
        $this->record(ConsoleEventSid::CommandInvalidInput, $reason);
    }

    public function unknown(string $command): void
    {
        $this->fail('unknown_command', $command);
        $this->record(ConsoleEventSid::CommandUnknown, $command);
    }

    public function abort(string $reason, int $exitCode = 130): void
    {
        $reason = self::fit($reason);
        $this->annotate(ConsoleAnnotationSid::ExitCode, $exitCode);
        $this->annotate(ConsoleAnnotationSid::ErrorKind, 'cancelled');
        $this->handle = $this->runtime->memory->resources->abort($this->handle, $reason);
        $this->record(ConsoleEventSid::CommandAborted, 'cancelled', $reason);
    }

    private static function fit(string $value, int $length = 240): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    private function annotate(RuntimeAnnotationId $key, string|int|float|bool|null $value): void
    {
        if (is_string($value)) {
            $value = self::fit($value);
        }

        $this->runtime->memory->resources->annotate($this->handle, $key, $value);
    }

    private function record(RuntimeEventId $type, string $valueA = '', string $valueB = ''): void
    {
        $this->runtime->memory->resources->recordEvent(
            resource: $this->handle,
            type: $type,
            valueA: self::fit($valueA),
            valueB: self::fit($valueB),
        );
    }
}

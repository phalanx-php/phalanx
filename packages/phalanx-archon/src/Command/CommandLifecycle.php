<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Archon\Runtime\Identity\ArchonAnnotationSid;
use Phalanx\Archon\Runtime\Identity\ArchonEventSid;
use Phalanx\Archon\Runtime\Identity\ArchonResourceSid;
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
    public const string RESOURCE_ATTRIBUTE = 'archon.command.resource_id';

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
        $resourceId = $memory->ids->nextRuntime('archon-command');
        $handle = $memory->resources->open(
            type: ArchonResourceSid::Command,
            id: $resourceId,
            parentResourceId: $scope->scopeId,
            ownerScopeId: $scope->scopeId,
        );

        $lifecycle = new self($runtime, $handle);
        $lifecycle->annotate(ArchonAnnotationSid::CommandName, $name);
        $lifecycle->annotate(ArchonAnnotationSid::ArgumentCount, $argumentCount);
        $lifecycle->annotate(ArchonAnnotationSid::DefaultCommand, $defaultCommand);
        $lifecycle->record(ArchonEventSid::CommandDispatched, $name);

        return $lifecycle;
    }

    public function activate(string $handler): void
    {
        $this->annotate(ArchonAnnotationSid::Handler, $handler);
        $this->handle = $this->runtime->memory->resources->activate($this->handle);
        $this->record(ArchonEventSid::CommandMatched, $handler);
    }

    public function close(int $exitCode): void
    {
        $this->annotate(ArchonAnnotationSid::ExitCode, $exitCode);
        $this->handle = $this->runtime->memory->resources->close($this->handle, "exit:$exitCode");
        $this->record(ArchonEventSid::CommandCompleted, (string) $exitCode);
    }

    public function fail(string $kind, string $reason = '', ?Throwable $exception = null): void
    {
        $this->annotate(ArchonAnnotationSid::ExitCode, 1);
        $this->annotate(ArchonAnnotationSid::ErrorKind, $kind);

        if ($exception !== null) {
            $this->annotate(ArchonAnnotationSid::ExceptionClass, $exception::class);
        }

        $reason = self::fit($reason);
        $this->handle = $this->runtime->memory->resources->fail($this->handle, $reason);
        $this->record(ArchonEventSid::CommandFailed, $kind, $reason);
    }

    public function invalidInput(string $reason, ?Throwable $exception = null): void
    {
        $this->fail('invalid_input', $reason, $exception);
        $this->record(ArchonEventSid::CommandInvalidInput, $reason);
    }

    public function unknown(string $command): void
    {
        $this->fail('unknown_command', $command);
        $this->record(ArchonEventSid::CommandUnknown, $command);
    }

    public function abort(string $reason, int $exitCode = 130): void
    {
        $reason = self::fit($reason);
        $this->annotate(ArchonAnnotationSid::ExitCode, $exitCode);
        $this->annotate(ArchonAnnotationSid::ErrorKind, 'cancelled');
        $this->handle = $this->runtime->memory->resources->abort($this->handle, $reason);
        $this->record(ArchonEventSid::CommandAborted, 'cancelled', $reason);
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

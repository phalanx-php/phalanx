<?php

declare(strict_types=1);

namespace Phalanx\Console\Runtime;

use Phalanx\Runtime\Identity\RuntimeAnnotationId;

/**
 * Stable identifiers for annotations Console attaches to managed resources
 * and trace records (command name, exit code, handler class, etc.). The
 * `console.*` prefix matches the convention Runtime uses for its own ids
 * so cross-package telemetry stays unambiguous.
 */
enum ConsoleAnnotationSid: string implements RuntimeAnnotationId
{
    case ArgumentCount = 'console.argv_count';
    case CommandName = 'console.command_name';
    case DefaultCommand = 'console.default_command';
    case ErrorKind = 'console.error_kind';
    case ExceptionClass = 'console.exception_class';
    case ExitCode = 'console.exit_code';
    case Handler = 'console.handler';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}

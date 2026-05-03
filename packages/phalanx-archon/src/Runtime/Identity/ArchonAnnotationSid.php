<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeAnnotationId;

enum ArchonAnnotationSid: string implements RuntimeAnnotationId
{
    case ArgumentCount = 'archon.argv_count';
    case CommandName = 'archon.command_name';
    case DefaultCommand = 'archon.default_command';
    case ErrorKind = 'archon.error_kind';
    case ExceptionClass = 'archon.exception_class';
    case ExitCode = 'archon.exit_code';
    case Handler = 'archon.handler';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
